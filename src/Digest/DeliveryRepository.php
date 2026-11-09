<?php

declare(strict_types=1);

namespace App\Digest;

use Doctrine\DBAL\Connection;

final readonly class DeliveryRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array{id: string, targetId: string}> */
    public function pending(string $runId, string $channel): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT d.id, d.target_id FROM deliveries d
            JOIN artifacts a ON a.id = d.artifact_id
            JOIN runs r ON r.id = a.run_id
            JOIN delivery_targets t ON t.id = d.target_id
            WHERE r.id = :run_id AND r.status = 'completed' AND r.published_at IS NOT NULL
              AND a.kind = CASE WHEN t.channel = 'email' THEN 'email_preview' ELSE 'post' END AND t.channel = :channel AND d.status IN ('queued', 'failed')
            ORDER BY d.id
            SQL, ['run_id' => $runId, 'channel' => $channel]);

        return array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'targetId' => (string) $row['target_id']], $rows);
    }

    /**
     * Called inside a transaction. The target lock also serializes sending with unsubscribe.
     *
     * @return array{recipient: string, locale: string, token: ?string, nextPart: int}|null
     */
    public function lockMessage(string $deliveryId, string $targetId, string $channel): ?array
    {
        $target = $this->connection->fetchAssociative(<<<'SQL'
            SELECT destination, locale, status, email_unsubscribe_token FROM delivery_targets
            WHERE id = :id AND channel = :channel FOR UPDATE SKIP LOCKED
            SQL, ['id' => $targetId, 'channel' => $channel]);
        if (false === $target) {
            return null;
        }

        $locked = $this->connection->fetchOne(<<<'SQL'
            SELECT d.next_part FROM deliveries d
            JOIN artifacts a ON a.id = d.artifact_id
            JOIN runs r ON r.id = a.run_id
            WHERE d.id = :id AND d.target_id = :target AND d.status IN ('queued', 'failed')
              AND a.kind = :kind AND r.status = 'completed' AND r.published_at IS NOT NULL
            FOR UPDATE OF d SKIP LOCKED
            SQL, ['id' => $deliveryId, 'target' => $targetId, 'kind' => 'email' === $channel ? 'email_preview' : 'post']);
        if (false === $locked) {
            return null;
        }
        if ('active' !== $target['status'] || ('email' === $channel && null === $target['email_unsubscribe_token'])) {
            $this->cancelPending($targetId);

            return null;
        }

        return [
            'recipient' => (string) $target['destination'],
            'locale' => (string) $target['locale'],
            'token' => null === $target['email_unsubscribe_token'] ? null : (string) $target['email_unsubscribe_token'],
            'nextPart' => (int) $locked,
        ];
    }

    public function attempt(string $id): void
    {
        $this->connection->executeStatement('UPDATE deliveries SET attempts = attempts + 1 WHERE id = :id', ['id' => $id]);
    }

    public function cancelPending(string $targetId): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE deliveries SET status = 'cancelled', last_error = NULL
            WHERE target_id = :id AND status IN ('queued', 'failed')
            SQL, ['id' => $targetId]);
    }

    public function sent(string $id, ?string $providerMessageId = null): void
    {
        $this->connection->executeStatement(
            "UPDATE deliveries SET status = 'sent', sent_at = CURRENT_TIMESTAMP, last_error = NULL, provider_message_id = :message_id WHERE id = :id",
            ['id' => $id, 'message_id' => $providerMessageId],
        );
    }

    public function advance(string $id): void
    {
        $this->connection->executeStatement(
            "UPDATE deliveries SET next_part = next_part + 1, status = 'queued', last_error = NULL WHERE id = :id",
            ['id' => $id],
        );
    }

    public function failed(string $id, string $errorCode): void
    {
        $this->connection->executeStatement(
            "UPDATE deliveries SET status = 'failed', last_error = :error WHERE id = :id",
            ['id' => $id, 'error' => $errorCode],
        );
    }

    /** @return array{sent: int, failed: int, queued: int} */
    public function counts(string $runId, string $channel): array
    {
        $counts = ['sent' => 0, 'failed' => 0, 'queued' => 0];
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT d.status, COUNT(*) AS total FROM deliveries d
            JOIN artifacts a ON a.id = d.artifact_id
            JOIN delivery_targets t ON t.id = d.target_id
            WHERE a.run_id = :id AND a.kind = CASE WHEN t.channel = 'email' THEN 'email_preview' ELSE 'post' END AND t.channel = :channel
            GROUP BY d.status
            SQL, ['id' => $runId, 'channel' => $channel]);
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row['total'];
            }
        }

        return $counts;
    }
}
