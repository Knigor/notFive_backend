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
        $this->connection->executeStatement('UPDATE runs SET settings_snapshot = settings_snapshot || CAST(:collection AS jsonb) WHERE id = :id', [
            'collection' => json_encode(['go_collection' => $collection], JSON_THROW_ON_ERROR),
            'id' => $runId,
        ]);
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
}
