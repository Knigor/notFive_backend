<?php

declare(strict_types=1);

namespace App\Mail;

use Doctrine\DBAL\Connection;

final readonly class EmailNewsletterRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array{id: string, targetId: string}> */
    public function pending(string $runId): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT d.id, d.target_id FROM deliveries d
            JOIN artifacts a ON a.id = d.artifact_id
            JOIN runs r ON r.id = a.run_id
            JOIN delivery_targets t ON t.id = d.target_id
            WHERE r.id = :run_id AND r.status = 'completed' AND r.published_at IS NOT NULL
              AND a.kind = 'email_preview' AND t.channel = 'email' AND d.status IN ('queued', 'failed')
            ORDER BY d.id
            SQL, ['run_id' => $runId]);

        return array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'targetId' => (string) $row['target_id']], $rows);
    }

    /**
     * Called inside a transaction. The target lock also serializes sending with unsubscribe.
     *
     * @return array{recipient: string, locale: string, token: string}|null
     */
    public function lockMessage(string $deliveryId, string $targetId): ?array
    {
        $target = $this->connection->fetchAssociative(<<<'SQL'
            SELECT destination, locale, status, email_unsubscribe_token FROM delivery_targets
            WHERE id = :id AND channel = 'email' FOR UPDATE SKIP LOCKED
            SQL, ['id' => $targetId]);
        if (false === $target) {
            return null;
        }

        $locked = $this->connection->fetchOne(<<<'SQL'
            SELECT d.id FROM deliveries d
            JOIN artifacts a ON a.id = d.artifact_id
            JOIN email_preview_details e ON e.artifact_id = a.id
            JOIN runs r ON r.id = a.run_id
            WHERE d.id = :id AND d.target_id = :target AND d.status IN ('queued', 'failed')
              AND a.kind = 'email_preview' AND r.status = 'completed' AND r.published_at IS NOT NULL
            FOR UPDATE OF d SKIP LOCKED
            SQL, ['id' => $deliveryId, 'target' => $targetId]);
        if (false === $locked) {
            return null;
        }
        if ('active' !== $target['status'] || null === $target['email_unsubscribe_token']) {
            $this->connection->executeStatement("UPDATE deliveries SET status = 'cancelled', last_error = NULL WHERE id = :id", ['id' => $deliveryId]);

            return null;
        }
        $this->connection->executeStatement('UPDATE deliveries SET attempts = attempts + 1 WHERE id = :id', ['id' => $deliveryId]);

        return [
            'recipient' => (string) $target['destination'],
            'locale' => (string) $target['locale'],
            'token' => (string) $target['email_unsubscribe_token'],
        ];
    }

    public function sent(string $id): void
    {
        $this->connection->executeStatement("UPDATE deliveries SET status = 'sent', sent_at = CURRENT_TIMESTAMP, last_error = NULL WHERE id = :id", ['id' => $id]);
    }

    public function failed(string $id): void
    {
        // SMTP exception text can contain addresses or credentials. Keep a stable internal code only.
        $this->connection->executeStatement("UPDATE deliveries SET status = 'failed', last_error = 'email_transport_failed' WHERE id = :id", ['id' => $id]);
    }

    /** @return array{emailSent: int, emailFailed: int, emailPending: int} */
    public function counts(string $runId): array
    {
        $counts = ['emailSent' => 0, 'emailFailed' => 0, 'emailPending' => 0];
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT d.status, COUNT(*) AS total FROM deliveries d
            JOIN artifacts a ON a.id = d.artifact_id
            JOIN delivery_targets t ON t.id = d.target_id
            WHERE a.run_id = :id AND a.kind = 'email_preview' AND t.channel = 'email'
            GROUP BY d.status
            SQL, ['id' => $runId]);
        foreach ($rows as $row) {
            $key = match ($row['status']) {
                'sent' => 'emailSent',
                'failed' => 'emailFailed',
                'queued' => 'emailPending',
                default => null,
            };
            if (null !== $key) {
                $counts[$key] = (int) $row['total'];
            }
        }

        return $counts;
    }
}
