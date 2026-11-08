<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Подтверждение email-подписок, отписка и ограничение писем подтверждения';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');

        $this->addSql('ALTER TABLE delivery_targets ADD email_confirmation_token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_targets ADD email_confirmation_expires_at TIMESTAMPTZ DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_targets ADD email_confirmation_sent_at TIMESTAMPTZ DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_targets ADD email_unsubscribe_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX delivery_targets_email_confirmation_token_idx ON delivery_targets (email_confirmation_token_hash) WHERE email_confirmation_token_hash IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX delivery_targets_email_unsubscribe_token_idx ON delivery_targets (email_unsubscribe_token) WHERE email_unsubscribe_token IS NOT NULL');
        $this->addSql("COMMENT ON COLUMN delivery_targets.email_confirmation_token_hash IS 'SHA-256 одноразовой ссылки подтверждения. Сохраняется после активации для идемпотентного подтверждения, удаляется при отписке.'");
        $this->addSql("COMMENT ON COLUMN delivery_targets.email_confirmation_expires_at IS 'Срок действия неподтверждённой ссылки: 24 часа.'");
        $this->addSql("COMMENT ON COLUMN delivery_targets.email_confirmation_sent_at IS 'Момент последней попытки отправить подтверждение; ограничивает повторные письма.'");
        $this->addSql("COMMENT ON COLUMN delivery_targets.email_unsubscribe_token IS 'Случайный секрет ссылки управления отпиской; не раскрывается публичным API.'");
        $this->addSql(<<<'SQL'
            CREATE TABLE email_subscription_limits (
                ip_hash VARCHAR(64) NOT NULL PRIMARY KEY,
                window_start TIMESTAMPTZ NOT NULL,
                attempts INTEGER NOT NULL,
                CONSTRAINT email_subscription_limits_attempts_check CHECK (attempts BETWEEN 1 AND 5)
            )
            SQL);
        $this->addSql("COMMENT ON TABLE email_subscription_limits IS 'Не более пяти писем подтверждения с одного SHA-256 IP за десять минут. Исходный IP не хранится.'");
        $this->addSql('ALTER TABLE deliveries DROP CONSTRAINT deliveries_status_check');
        $this->addSql("ALTER TABLE deliveries ADD CONSTRAINT deliveries_status_check CHECK (status IN ('queued', 'sent', 'failed', 'cancelled'))");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');

        $this->addSql("UPDATE deliveries SET status = 'failed' WHERE status = 'cancelled'");
        $this->addSql('ALTER TABLE deliveries DROP CONSTRAINT deliveries_status_check');
        $this->addSql("ALTER TABLE deliveries ADD CONSTRAINT deliveries_status_check CHECK (status IN ('queued', 'sent', 'failed'))");
        $this->addSql('DROP TABLE email_subscription_limits');
        $this->addSql('DROP INDEX delivery_targets_email_confirmation_token_idx');
        $this->addSql('DROP INDEX delivery_targets_email_unsubscribe_token_idx');
        $this->addSql('ALTER TABLE delivery_targets DROP COLUMN email_confirmation_token_hash');
        $this->addSql('ALTER TABLE delivery_targets DROP COLUMN email_confirmation_expires_at');
        $this->addSql('ALTER TABLE delivery_targets DROP COLUMN email_confirmation_sent_at');
        $this->addSql('ALTER TABLE delivery_targets DROP COLUMN email_unsubscribe_token');
    }
}
