<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108111235 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Язык подписки Telegram и защита от повторной обработки обновлений';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');

        $this->addSql("ALTER TABLE delivery_targets ADD locale VARCHAR(2) NOT NULL DEFAULT 'ru'");
        $this->addSql("ALTER TABLE delivery_targets ADD CONSTRAINT delivery_targets_locale_check CHECK (locale IN ('ru', 'en'))");
        $this->addSql("COMMENT ON COLUMN delivery_targets.locale IS 'Язык сообщений получателя: русский или английский.'");
        $this->addSql(<<<'SQL'
            CREATE TABLE telegram_updates (
                update_id BIGINT NOT NULL PRIMARY KEY,
                processed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);
        $this->addSql("COMMENT ON TABLE telegram_updates IS 'Обработанные обновления Telegram для защиты от повторной обработки при polling.'");
        $this->addSql("COMMENT ON COLUMN telegram_updates.update_id IS 'Уникальный идентификатор обновления, выданный Telegram.'");
        $this->addSql("COMMENT ON COLUMN telegram_updates.processed_at IS 'Момент регистрации обновления для защиты от повторной обработки.'");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');

        $this->addSql('DROP TABLE telegram_updates');
        $this->addSql('ALTER TABLE delivery_targets DROP CONSTRAINT delivery_targets_locale_check');
        $this->addSql('ALTER TABLE delivery_targets DROP COLUMN locale');
    }
}
