<?php

declare(strict_types=1);

namespace App\Digest;

use Doctrine\DBAL\Connection;

final readonly class DigestPublicationRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array{runId: string, publishedAt: string, alreadyPublished: bool} */
    public function publish(string $runId): array
    {
        return $this->connection->transactional(function () use ($runId): array {
            $run = $this->connection->fetchAssociative('SELECT status, published_at FROM runs WHERE id = :id FOR UPDATE', ['id' => $runId]);
            if (false === $run) {
                throw new \InvalidArgumentException('not_found');
            }
            if ('completed' !== $run['status']) {
                throw new \InvalidArgumentException('not_ready');
            }

            $artifact = $this->connection->fetchAssociative(<<<'SQL'
                SELECT a.id FROM artifacts a
                JOIN email_preview_details e ON e.artifact_id = a.id
                WHERE a.run_id = :id AND a.kind = 'email_preview'
                  AND btrim(a.content) <> '' AND btrim(e.subject) <> ''
                  AND (SELECT COUNT(*) FROM artifacts WHERE run_id = :id) = 4
                SQL, ['id' => $runId]);
            if (false === $artifact) {
                throw new \InvalidArgumentException('missing_files');
            }

            $alreadyPublished = null !== $run['published_at'];
            if (!$alreadyPublished) {
                $run['published_at'] = $this->connection->fetchOne('UPDATE runs SET published_at = CURRENT_TIMESTAMP WHERE id = :id RETURNING published_at', ['id' => $runId]);
                // Snapshot subscribers only once. Retrying publication must not send old issues to new subscribers.
                $this->connection->executeStatement(<<<'SQL'
                    WITH recipients AS MATERIALIZED (
                        SELECT id FROM delivery_targets
                        WHERE channel = 'email' AND status = 'active' AND confirmed_at IS NOT NULL
                          AND email_unsubscribe_token IS NOT NULL
                        ORDER BY id FOR UPDATE
                    )
                    INSERT INTO deliveries (id, artifact_id, target_id, status)
                    SELECT gen_random_uuid(), :artifact_id, id, 'queued'
                    FROM recipients
                    ON CONFLICT (artifact_id, target_id) DO NOTHING
                    SQL, ['artifact_id' => $artifact['id']]);
            }

            return [
                'runId' => $runId,
                'publishedAt' => (new \DateTimeImmutable((string) $run['published_at']))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format(DATE_ATOM),
                'alreadyPublished' => $alreadyPublished,
            ];
        });
    }
}
