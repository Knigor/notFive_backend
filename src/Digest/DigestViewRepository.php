<?php

declare(strict_types=1);

namespace App\Digest;

use Doctrine\DBAL\Connection;

final readonly class DigestViewRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<string> */
    public function publishedDates(): array
    {
        $dates = $this->connection->fetchFirstColumn(<<<'SQL'
            SELECT DISTINCT to_char(control_time AT TIME ZONE 'Europe/Moscow', 'YYYY-MM-DD') AS issue_date
            FROM runs
            WHERE status = 'completed' AND published_at IS NOT NULL
            ORDER BY issue_date DESC
            SQL);

        return array_map(static fn (mixed $date): string => (string) $date, $dates);
    }

    public function publishedForDate(\DateTimeImmutable $day): ?DigestView
    {
        $moscow = new \DateTimeZone('Europe/Moscow');
        $start = $day->setTimezone($moscow)->setTime(0, 0);
        $run = $this->connection->fetchAssociative(<<<'SQL'
            SELECT r.id, r.control_time, r.published_at,
                   (SELECT COUNT(*) FROM materials m WHERE m.run_id = r.id) AS total_materials
            FROM runs r
            WHERE r.status = 'completed' AND r.published_at IS NOT NULL
              AND r.control_time >= :day_start AND r.control_time < :day_end
            ORDER BY r.published_at DESC, r.control_time DESC, r.id DESC
            LIMIT 1
            SQL, [
            'day_start' => $start->format(DATE_ATOM),
            'day_end' => $start->modify('+1 day')->format(DATE_ATOM),
        ]);
        if (false === $run) {
            return null;
        }

        $runId = (string) $run['id'];
        $control = (new \DateTimeImmutable((string) $run['control_time']))->setTimezone($moscow);
        $published = (new \DateTimeImmutable((string) $run['published_at']))->setTimezone($moscow);

        return new DigestView(
            $runId,
            $control->format(DATE_ATOM),
            $control->modify('-24 hours')->format(DATE_ATOM),
            'Europe/Moscow',
            'ru',
            $published->format(DATE_ATOM),
            (int) $run['total_materials'],
            $this->events($runId),
            $this->files($runId),
        );
    }

    /** @return list<DigestEventView> */
    private function events(string $runId): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT id, rank, title, summary, consequences, selection_reason
            FROM events WHERE run_id = :run_id ORDER BY rank LIMIT 5
            SQL, ['run_id' => $runId]);
        $sources = $this->sources($runId);

        return array_map(static fn (array $row): DigestEventView => new DigestEventView(
            (string) $row['id'],
            (int) $row['rank'],
            (string) $row['title'],
            (string) $row['summary'],
            (string) $row['consequences'],
            (string) $row['selection_reason'],
            $sources[(string) $row['id']] ?? [],
        ), $rows);
    }

    /** @return array<string, list<DigestSourceView>> */
    private function sources(string $runId): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT em.event_id, m.id, m.source_name, m.url, m.published_at
            FROM event_materials em JOIN materials m ON m.id = em.material_id
            WHERE em.run_id = :run_id
            ORDER BY em.is_primary_source DESC, m.input_id, m.id
            SQL, ['run_id' => $runId]);
        $sources = [];
        $moscow = new \DateTimeZone('Europe/Moscow');
        foreach ($rows as $row) {
            $sources[(string) $row['event_id']][] = new DigestSourceView(
                (string) $row['id'],
                (string) ($row['source_name'] ?? ''),
                null === $row['url'] ? null : (string) $row['url'],
                null === $row['published_at'] ? null : (new \DateTimeImmutable((string) $row['published_at']))->setTimezone($moscow)->format(DATE_ATOM),
            );
        }

        return $sources;
    }

    private function files(string $runId): ?DigestFilesView
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT a.kind, a.content, e.subject
            FROM artifacts a
            LEFT JOIN email_preview_details e ON e.artifact_id = a.id
            WHERE a.run_id = :run_id
            SQL, ['run_id' => $runId]);
        if ([] === $rows) {
            return null;
        }

        $contents = [];
        $subject = null;
        foreach ($rows as $row) {
            $kind = (string) $row['kind'];
            $contents[$kind] = (string) $row['content'];
            if ('email_preview' === $kind) {
                $subject = null === $row['subject'] ? null : (string) $row['subject'];
            }
        }

        return new DigestFilesView(
            $contents['digest'] ?? null,
            $contents['post'] ?? null,
            $contents['email_preview'] ?? null,
            $contents['journal'] ?? null,
            $subject,
        );
    }
}
