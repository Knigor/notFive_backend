<?php

declare(strict_types=1);

namespace App\Dataset;

final class DatasetFileReader
{
    private const FIELDS = ['id', 'title', 'text', 'source', 'url', 'published_at', 'missing', 'error'];

    public function read(string $path): ParsedDataset
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'json' => $this->readJson($path),
            'csv' => $this->readCsv($path),
            default => throw new DatasetImportException('unsupported_format', 'Only JSON and CSV dataset files are supported.'),
        };
    }

    private function readJson(string $path): ParsedDataset
    {
        $contents = file_get_contents($path);

        if (false === $contents) {
            throw new DatasetImportException('unreadable_file', 'Cannot read the dataset file.');
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DatasetImportException('invalid_json', 'Invalid dataset JSON: '.$exception->getMessage(), $exception);
        }

        if (!is_array($data) || !is_array($data['items'] ?? null) || !array_is_list($data['items']) || !is_string($data['control_time'] ?? null) || !is_string($data['period_start'] ?? null)) {
            throw new DatasetImportException('invalid_structure', 'Dataset JSON requires items, control_time and period_start.');
        }

        $controlTime = self::parseTime($data['control_time']);
        $periodStart = self::parseTime($data['period_start']);

        if ($periodStart->getTimestamp() !== $controlTime->getTimestamp() - 86400) {
            throw new DatasetImportException('invalid_period', 'The dataset period must be exactly 24 hours.');
        }

        if (isset($data['timezone']) && 'Europe/Moscow' !== $data['timezone']) {
            throw new DatasetImportException('invalid_timezone', 'The dataset timezone must be Europe/Moscow.');
        }

        $datasetName = $data['dataset'] ?? null;

        if (null !== $datasetName && !is_string($datasetName)) {
            throw new DatasetImportException('invalid_structure', 'The dataset name must be a string.');
        }

        return new ParsedDataset($data['items'], $controlTime, $periodStart, $datasetName);
    }

    private function readCsv(string $path): ParsedDataset
    {
        $handle = fopen($path, 'rb');

        if (false === $handle) {
            throw new DatasetImportException('unreadable_file', 'Cannot read the dataset CSV.');
        }

        try {
            $header = fgetcsv($handle, 0, ';', '"', '');

            if (false === $header) {
                throw new DatasetImportException('invalid_csv', 'The dataset CSV is empty.');
            }

            if (!is_string($header[0] ?? null)) {
                throw new DatasetImportException('invalid_csv', 'Invalid dataset CSV header.');
            }

            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

            if (self::FIELDS !== $header) {
                throw new DatasetImportException('invalid_csv', 'Unexpected dataset CSV columns.');
            }

            $rows = [];

            while (false !== ($values = fgetcsv($handle, 0, ';', '"', ''))) {
                if ([null] === $values) {
                    continue;
                }

                if (count($values) !== count(self::FIELDS)) {
                    $rows[] = [
                        'id' => is_string($values[0] ?? null) ? $values[0] : null,
                        '_csv_error' => 'Unexpected number of CSV columns.',
                    ];

                    continue;
                }

                $row = array_combine(self::FIELDS, $values);
                $row['missing'] = '' === $row['missing'] ? [] : array_map('trim', explode(',', (string) $row['missing']));
                $row['published_at'] = '' === $row['published_at'] ? null : $row['published_at'];
                $row['error'] = '' === $row['error'] ? null : $row['error'];
                $rows[] = $row;
            }

            return new ParsedDataset($rows, null, null, null);
        } finally {
            fclose($handle);
        }
    }

    public static function parseTime(string $value): \DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new DatasetImportException('invalid_timestamp', 'Timestamp must be ISO 8601 with a timezone: '.$value);
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new DatasetImportException('invalid_timestamp', 'Invalid timestamp: '.$value, $exception);
        }

        if ($date->format('Y-m-d\TH:i:sP') !== str_replace('Z', '+00:00', $value)) {
            throw new DatasetImportException('invalid_timestamp', 'Invalid timestamp: '.$value);
        }

        return $date;
    }
}
