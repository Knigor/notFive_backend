<?php

declare(strict_types=1);

namespace App\Telegram;

use Doctrine\DBAL\Connection;

final readonly class TelegramSubscriptionRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function register(string $chatId): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO delivery_targets (id, channel, destination, status, locale)
            VALUES (gen_random_uuid(), 'telegram', :chat_id, 'pending', 'ru')
            ON CONFLICT (channel, destination) DO NOTHING
            SQL, ['chat_id' => $chatId]);
    }

    public function subscribe(string $chatId): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO delivery_targets (id, channel, destination, status, confirmed_at, locale)
            VALUES (gen_random_uuid(), 'telegram', :chat_id, 'active', CURRENT_TIMESTAMP, 'ru')
            ON CONFLICT (channel, destination) DO UPDATE
            SET status = 'active', confirmed_at = COALESCE(delivery_targets.confirmed_at, CURRENT_TIMESTAMP)
            SQL, ['chat_id' => $chatId]);
    }

    public function pause(string $chatId): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE delivery_targets SET status = 'unsubscribed'
            WHERE channel = 'telegram' AND destination = :chat_id
            SQL, ['chat_id' => $chatId]);
    }

    public function setLocale(string $chatId, string $locale): void
    {
        if (!in_array($locale, ['ru', 'en'], true)) {
            throw new \InvalidArgumentException('Unsupported locale.');
        }

        $this->register($chatId);
        $this->connection->executeStatement(<<<'SQL'
            UPDATE delivery_targets SET locale = :locale
            WHERE channel = 'telegram' AND destination = :chat_id
            SQL, ['chat_id' => $chatId, 'locale' => $locale]);
    }

    /** @return array{ status: string, locale: string } */
    public function get(string $chatId): array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT status, locale FROM delivery_targets
            WHERE channel = 'telegram' AND destination = :chat_id
            LIMIT 1
            SQL, ['chat_id' => $chatId]);

        if (false === $row) {
            return ['status' => 'pending', 'locale' => 'ru'];
        }

        return ['status' => (string) $row['status'], 'locale' => (string) $row['locale']];
    }

    /** @return list<array{ id: string, destination: string, locale: string }> */
    public function activeTargets(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT id, destination, locale FROM delivery_targets
            WHERE channel = 'telegram' AND status = 'active'
            ORDER BY id
            SQL);

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'destination' => (string) $row['destination'],
            'locale' => (string) $row['locale'],
        ], $rows);
    }
}
