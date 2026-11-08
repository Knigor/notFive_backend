<?php

declare(strict_types=1);

namespace App\SiteSource;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class SiteSourceRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<SiteSource> */
    public function all(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT id, name, task_url, search_domain, status
            FROM site_sources
            ORDER BY CASE status WHEN 'ready' THEN 0 WHEN 'candidate' THEN 1 ELSE 2 END, name
            SQL);

        return array_map(self::fromRow(...), $rows);
    }

    /** @param list<string> $ids
     * @return list<SiteSource>
     */
    public function readyByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT id, name, task_url, search_domain, status
            FROM site_sources
            WHERE id IN (:ids) AND status = 'ready'
            SQL, ['ids' => $ids], ['ids' => ArrayParameterType::STRING]);
        $sources = [];

        foreach ($rows as $row) {
            $sources[(string) $row['id']] = self::fromRow($row);
        }

        return array_values(array_filter(array_map(static fn (string $id): ?SiteSource => $sources[$id] ?? null, $ids)));
    }

    /** @param array<string, mixed> $row */
    private static function fromRow(array $row): SiteSource
    {
        return new SiteSource((string) $row['id'], (string) $row['name'], (string) $row['task_url'], (string) $row['search_domain'], (string) $row['status']);
    }
}
