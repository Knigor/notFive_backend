<?php

declare(strict_types=1);

namespace App\Telegram;

use Doctrine\DBAL\Connection;

final readonly class TelegramUpdateRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function hasProcessed(int $updateId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM telegram_updates WHERE update_id = :update_id LIMIT 1',
            ['update_id' => $updateId],
        );
    }

    public function markProcessed(int $updateId): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO telegram_updates (update_id) VALUES (:update_id)
            ON CONFLICT (update_id) DO NOTHING
            SQL, ['update_id' => $updateId]);
    }
}
