<?php

declare(strict_types=1);

namespace App\Telegram;

use Doctrine\DBAL\Connection;

final readonly class TelegramDeliveryRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function claim(string $artifactId, string $targetId): ?string
    {
        $id = $this->connection->fetchOne(<<<'SQL'
            INSERT INTO deliveries (id, artifact_id, target_id, status, attempts)
            VALUES (gen_random_uuid(), :artifact_id, :target_id, 'queued', 1)
            ON CONFLICT (artifact_id, target_id) DO UPDATE
            SET status = 'queued', attempts = deliveries.attempts + 1, last_error = NULL
            WHERE deliveries.status = 'failed'
            RETURNING id
            SQL, ['artifact_id' => $artifactId, 'target_id' => $targetId]);

        return false === $id ? null : (string) $id;
    }

    public function sent(string $deliveryId, string $messageId): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE deliveries
            SET status = 'sent', provider_message_id = :message_id, sent_at = CURRENT_TIMESTAMP, last_error = NULL
            WHERE id = :delivery_id AND status = 'queued'
            SQL, ['delivery_id' => $deliveryId, 'message_id' => $messageId]);
    }

    public function failed(string $deliveryId, string $error): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE deliveries SET status = 'failed', last_error = :error
            WHERE id = :delivery_id AND status = 'queued'
            SQL, ['delivery_id' => $deliveryId, 'error' => $error]);
    }
}
