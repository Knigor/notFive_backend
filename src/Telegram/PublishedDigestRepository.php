<?php

declare(strict_types=1);

namespace App\Telegram;

use Doctrine\DBAL\Connection;

final readonly class PublishedDigestRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function latest(): ?PublishedDigest
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT a.id AS artifact_id, a.run_id, a.content
            FROM artifacts a
            JOIN runs r ON r.id = a.run_id
            WHERE a.kind = 'post' AND r.status = 'completed' AND r.published_at IS NOT NULL
            ORDER BY r.published_at DESC, a.id DESC
            LIMIT 1
            SQL);

        if (false === $row) {
            return null;
        }

        $events = [];
        $result = $this->connection->executeQuery(<<<'SQL'
            SELECT id, rank, title, summary, consequences
            FROM events WHERE run_id = :run_id ORDER BY rank LIMIT 5
            SQL, ['run_id' => $row['run_id']]);

        foreach ($result->iterateAssociative() as $event) {
            $events[] = new PublishedEvent(
                (string) $event['id'],
                (int) $event['rank'],
                (string) $event['title'],
                (string) $event['summary'],
                (string) $event['consequences'],
                [],
            );
        }

        return new PublishedDigest((string) $row['artifact_id'], (string) $row['content'], $events);
    }

    public function event(string $eventId): ?PublishedEvent
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $eventId)) {
            return null;
        }

        $event = $this->connection->fetchAssociative(<<<'SQL'
            SELECT e.id, e.rank, e.title, e.summary, e.consequences
            FROM events e
            JOIN runs r ON r.id = e.run_id
            WHERE e.id = :event_id AND r.status = 'completed' AND r.published_at IS NOT NULL
            LIMIT 1
            SQL, ['event_id' => $eventId]);

        if (false === $event) {
            return null;
        }

        $sources = [];
        $result = $this->connection->executeQuery(<<<'SQL'
            SELECT m.source_name, m.url
            FROM event_materials em
            JOIN materials m ON m.id = em.material_id
            WHERE em.event_id = :event_id
            ORDER BY em.is_primary_source DESC, m.source_name, m.id
            SQL, ['event_id' => $eventId]);

        foreach ($result->iterateAssociative() as $source) {
            $name = (string) ($source['source_name'] ?? '');
            $url = (string) ($source['url'] ?? '');
            $sources[] = '' !== $url ? trim($name.' '.$url) : $name;
        }

        return new PublishedEvent(
            (string) $event['id'],
            (int) $event['rank'],
            (string) $event['title'],
            (string) $event['summary'],
            (string) $event['consequences'],
            $sources,
        );
    }
}
