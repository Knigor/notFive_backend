<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Очередь писем для отдельного отправителя и защита от повторного подтверждения';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');
        $this->addSql('ALTER TABLE delivery_targets ADD email_confirmation_request_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_targets ADD email_confirmation_delivered_at TIMESTAMPTZ DEFAULT NULL');
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
                id BIGSERIAL NOT NULL PRIMARY KEY,
                body TEXT NOT NULL,
                headers TEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX messenger_messages_queue_idx ON messenger_messages (queue_name, available_at, delivered_at, id)');
        $this->addSql("COMMENT ON TABLE messenger_messages IS 'Задания email и окончательные ошибки. Содержит ID подписки/доставки, без SMTP-паролей и без исходного токена подтверждения.'");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('ALTER TABLE delivery_targets DROP COLUMN email_confirmation_request_id');
        $this->addSql('ALTER TABLE delivery_targets DROP COLUMN email_confirmation_delivered_at');
    }
}
