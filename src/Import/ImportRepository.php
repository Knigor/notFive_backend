<?php

declare(strict_types=1);

namespace App\Import;

use App\Dataset\DatasetFileReader;
use App\Dataset\DatasetMaterial;
use Doctrine\DBAL\Connection;

final readonly class ImportRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** @param array<string, mixed> $settings */
    public function createRun(\DateTimeImmutable $controlTime, array $settings): string
    {
        return (string) $this->connection->fetchOne(<<<'SQL'
            INSERT INTO runs (id, control_time, status, model_version, prompt_version, settings_snapshot)
            VALUES (gen_random_uuid(), :control_time, 'queued', NULL, NULL, :settings_snapshot)
            RETURNING id
            SQL, [
            'control_time' => $controlTime->format(DATE_ATOM),
            'settings_snapshot' => json_encode($settings, JSON_THROW_ON_ERROR),
        ]);
    }

    public function createInput(string $runId, string $kind, string $name, string $locator, ?string $sha256, string $status, ?string $siteSourceId = null): string
    {
        return (string) $this->connection->fetchOne(<<<'SQL'
            INSERT INTO run_inputs (id, run_id, kind, name, locator, sha256, status, site_source_id)
            VALUES (gen_random_uuid(), :run_id, :kind, :name, :locator, :sha256, :status, :site_source_id)
            RETURNING id
            SQL, [
            'run_id' => $runId,
            'kind' => $kind,
            'name' => $name,
            'locator' => $locator,
            'sha256' => $sha256,
            'status' => $status,
            'site_source_id' => $siteSourceId,
        ]);
    }

    public function saveMaterial(string $runId, string $inputId, DatasetMaterial $material): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO materials (
                id, run_id, run_input_id, input_id, title, body, source_name, url,
                published_at, input_missing, source_error
            ) VALUES (
                gen_random_uuid(), :run_id, :run_input_id, :input_id, :title, :body, :source_name, :url,
                :published_at, :input_missing, :source_error
            )
            SQL, [
            'run_id' => $runId,
            'run_input_id' => $inputId,
            'input_id' => $material->inputId,
            'title' => $material->title,
            'body' => $material->body,
            'source_name' => $material->sourceName,
            'url' => $material->url,
            'published_at' => null === $material->publishedAt || '' === $material->publishedAt ? null : DatasetFileReader::parseTime($material->publishedAt)->format(DATE_ATOM),
            'input_missing' => json_encode($material->missing, JSON_THROW_ON_ERROR),
            'source_error' => $material->sourceError,
        ]);
    }

    public function saveSearchMaterial(string $runId, string $inputId, DatasetMaterial $material): bool
    {
        $id = $this->connection->fetchOne(<<<'SQL'
            INSERT INTO materials (
                id, run_id, run_input_id, input_id, title, body, source_name, url,
                published_at, input_missing, source_error
            ) VALUES (
                gen_random_uuid(), :run_id, :run_input_id, :input_id, :title, :body, :source_name, :url,
                :published_at, :input_missing, :source_error
            )
            ON CONFLICT (run_input_id, input_id) DO NOTHING
            RETURNING id
            SQL, [
            'run_id' => $runId,
            'run_input_id' => $inputId,
            'input_id' => $material->inputId,
            'title' => $material->title,
            'body' => $material->body,
            'source_name' => $material->sourceName,
            'url' => $material->url,
            'published_at' => null === $material->publishedAt || '' === $material->publishedAt ? null : DatasetFileReader::parseTime($material->publishedAt)->format(DATE_ATOM),
            'input_missing' => json_encode($material->missing, JSON_THROW_ON_ERROR),
            'source_error' => $material->sourceError,
        ]);

        return false !== $id;
    }

    /** @return list<array{id: string, title: ?string, text: ?string, source: ?string, url: ?string, publishedAt: ?string, missing: list<string>, error: ?string}> */
    public function getRunMaterials(string $runId): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT input_id, title, body, source_name, url, published_at, input_missing, source_error
            FROM materials
            WHERE run_id = :run_id
            ORDER BY id
            SQL, ['run_id' => $runId]);

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['input_id'],
            'title' => $row['title'],
            'text' => $row['body'],
            'source' => $row['source_name'],
            'url' => $row['url'],
            'publishedAt' => null === $row['published_at'] ? null : (new \DateTimeImmutable((string) $row['published_at']))->format(DATE_ATOM),
            'missing' => json_decode((string) $row['input_missing'], true, 512, JSON_THROW_ON_ERROR),
            'error' => $row['source_error'],
        ], $rows);
    }

    /** @param array<string, mixed> $collection */
    public function recordGoCollection(string $runId, array $collection): void
    {
        $snapshot = $this->connection->fetchOne('SELECT settings_snapshot FROM runs WHERE id = :id', ['id' => $runId]);
        if (!is_string($snapshot)) {
            throw new \UnexpectedValueException('Run settings are missing.');
        }
        $settings = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($settings)) {
            throw new \UnexpectedValueException('Invalid run settings.');
        }
        $this->connection->executeStatement('UPDATE runs SET settings_snapshot = :snapshot WHERE id = :id', [
            'snapshot' => json_encode([...$settings, 'go_collection' => $collection], JSON_THROW_ON_ERROR),
            'id' => $runId,
        ]);
    }

    /** @param list<array<string, mixed>> $errors */
    public function recordCollectionImportErrors(string $runId, array $errors): void
    {
        $snapshot = $this->connection->fetchOne('SELECT settings_snapshot FROM runs WHERE id = :id', ['id' => $runId]);
        $settings = json_decode((string) $snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($settings)) {
            throw new \UnexpectedValueException('Invalid run settings.');
        }
        $settings['collection_import_errors'] = $errors;
        $settings['collection_import_complete'] = true;
        $this->connection->update('runs', ['settings_snapshot' => json_encode($settings, JSON_THROW_ON_ERROR)], ['id' => $runId]);
    }

    public function saveDatasetError(string $inputId, int $rowNumber, ?string $materialId, string $error): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO dataset_import_errors (id, run_input_id, row_number, input_id, error)
            VALUES (gen_random_uuid(), :run_input_id, :row_number, :input_id, :error)
            SQL, [
            'run_input_id' => $inputId,
            'row_number' => $rowNumber,
            'input_id' => $materialId,
            'error' => $error,
        ]);
    }

    public function updateInput(string $inputId, string $status, ?string $error = null): void
    {
        $this->connection->executeStatement('UPDATE run_inputs SET status = :status, error = :error WHERE id = :id', [
            'status' => $status,
            'error' => $error,
            'id' => $inputId,
        ]);
    }

    public function failRun(string $runId, string $error): void
    {
        $this->connection->executeStatement("UPDATE runs SET status = 'failed', error = :error, started_at = COALESCE(started_at, CURRENT_TIMESTAMP), finished_at = CURRENT_TIMESTAMP WHERE id = :id", [
            'error' => $error,
            'id' => $runId,
        ]);
    }

    public function claimNextRun(): ?string
    {
        $id = $this->connection->fetchOne(<<<'SQL'
            UPDATE runs SET status = 'running', started_at = CURRENT_TIMESTAMP
            WHERE id = (SELECT id FROM runs WHERE status = 'queued' ORDER BY created_at, id LIMIT 1)
              AND status = 'queued'
            RETURNING id
            SQL);

        return false === $id ? null : (string) $id;
    }

    /** @return list<array{id: string, locator: string}> */
    public function siteInputs(string $runId): array
    {
        $rows = $this->connection->fetchAllAssociative("SELECT id, locator FROM run_inputs WHERE run_id = :run_id AND kind = 'site' ORDER BY id", ['run_id' => $runId]);

        return array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'locator' => (string) $row['locator']], $rows);
    }

    public function failPendingInputs(string $runId): void
    {
        $this->connection->executeStatement("UPDATE run_inputs SET status = 'failed', error = 'run_processing_failed' WHERE run_id = :run_id AND status = 'pending'", ['run_id' => $runId]);
    }

    public function requeue(string $runId, bool $allowRunning): bool
    {
        $statuses = $allowRunning ? "('failed', 'running')" : "('failed')";

        return 1 === $this->connection->executeStatement("UPDATE runs SET status = 'queued', error = NULL, started_at = NULL, finished_at = NULL WHERE id = :id AND status IN ".$statuses, ['id' => $runId]);
    }
}
