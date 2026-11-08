<?php

declare(strict_types=1);

namespace App\Dataset;

use App\Import\ImportRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class DatasetImporter
{
    public function __construct(
        private Connection $connection,
        private ImportRepository $repository,
        private DatasetFileReader $reader,
        private ValidatorInterface $validator,
        private string $storageDirectory,
    ) {
    }

    public function import(string $sourcePath, ?\DateTimeImmutable $requestedTime = null, ?string $sourceName = null): DatasetImportResult
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new DatasetImportException('unreadable_file', 'The dataset file is not readable.');
        }

        $size = filesize($sourcePath);

        if (false === $size || $size > 50 * 1024 * 1024) {
            throw new DatasetImportException('file_too_large', 'The dataset file exceeds the 50 MB limit.');
        }

        if (!is_dir($this->storageDirectory) && !mkdir($this->storageDirectory, 0700, true) && !is_dir($this->storageDirectory)) {
            throw new DatasetImportException('storage_error', 'Cannot create the dataset storage directory.');
        }

        $sourceName ??= basename($sourcePath);
        $extension = strtolower(pathinfo($sourceName, PATHINFO_EXTENSION));
        $target = $this->storageDirectory.'/'.bin2hex(random_bytes(16)).'.'.$extension;

        if (!copy($sourcePath, $target)) {
            throw new DatasetImportException('storage_error', 'Cannot store the original dataset file.');
        }

        chmod($target, 0600);

        try {
            $dataset = $this->reader->read($target);
            $controlTime = $this->resolveControlTime($dataset, $requestedTime);
            $hash = hash_file('sha256', $target);

            if (false === $hash) {
                throw new DatasetImportException('storage_error', 'Cannot hash the stored dataset file.');
            }

            return $this->connection->transactional(function () use ($dataset, $controlTime, $target, $sourceName, $extension, $hash): DatasetImportResult {
                $snapshot = [
                    'dataset' => $dataset->datasetName,
                    'period_start' => $dataset->periodStart?->format(DATE_ATOM),
                    'format' => $extension,
                ];
                $runId = $this->repository->createRun($controlTime, $snapshot);
                $inputId = $this->repository->createInput($runId, 'dataset', basename($sourceName), $target, $hash, 'completed');

                $imported = 0;
                $failed = 0;
                $seenIds = [];

                foreach ($dataset->rows as $index => $row) {
                    $rowNumber = $index + 1;
                    $error = $this->validateRow($row, $seenIds);

                    if (null !== $error) {
                        $this->repository->saveDatasetError($inputId, $rowNumber, is_array($row) && is_string($row['id'] ?? null) ? $row['id'] : null, $error);
                        ++$failed;

                        continue;
                    }

                    $material = DatasetMaterial::fromRow($row);
                    $seenIds[$material->inputId] = true;
                    $this->repository->saveMaterial($runId, $inputId, $material);
                    ++$imported;
                }

                return new DatasetImportResult($runId, $imported, $failed, $controlTime);
            });
        } catch (\Throwable $exception) {
            unlink($target);

            throw $exception;
        }
    }

    private function resolveControlTime(ParsedDataset $dataset, ?\DateTimeImmutable $requestedTime): \DateTimeImmutable
    {
        if (null !== $dataset->controlTime && null !== $requestedTime && $dataset->controlTime->getTimestamp() !== $requestedTime->getTimestamp()) {
            throw new DatasetImportException('control_time_conflict', 'The requested control time conflicts with the dataset metadata.');
        }

        return $dataset->controlTime ?? $requestedTime ?? throw new DatasetImportException('csv_time_required', 'CSV import requires --control-time.');
    }

    /** @param array<string, bool> $seenIds */
    private function validateRow(mixed $row, array $seenIds): ?string
    {
        if (!is_array($row)) {
            return 'Publication must be an object.';
        }

        if (isset($row['_csv_error'])) {
            return (string) $row['_csv_error'];
        }

        $material = DatasetMaterial::fromRow($row);
        $violations = $this->validator->validate($material);

        if (count($violations) > 0) {
            return (string) $violations;
        }

        if (isset($seenIds[$material->inputId])) {
            return 'Duplicate publication ID.';
        }

        if (null !== $material->publishedAt && '' !== $material->publishedAt) {
            try {
                DatasetFileReader::parseTime($material->publishedAt);
            } catch (DatasetImportException $exception) {
                return $exception->getMessage();
            }
        }

        return null;
    }
}
