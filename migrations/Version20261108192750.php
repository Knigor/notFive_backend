<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108192750 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Поля структурированного выпуска и группы журнала для экспорта в Symfony';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');

        $this->addSql('ALTER TABLE events ADD COLUMN group_key TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE events ADD COLUMN post_text TEXT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX events_run_group_key_unique ON events (run_id, group_key) WHERE group_key IS NOT NULL');
        $this->addSql('ALTER TABLE materials ADD COLUMN journal_group TEXT DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN events.group_key IS 'Идентификатор группы из структурированного ответа модели для связи с исходными решениями.'");
        $this->addSql("COMMENT ON COLUMN events.post_text IS 'Краткий текст того же события для post.md; NULL у старых выпусков.'");
        $this->addSql("COMMENT ON COLUMN materials.journal_group IS 'Подпись группы или групп для строки journal.csv; NULL до анализа.'");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');

        $this->addSql('ALTER TABLE materials DROP COLUMN journal_group');
        $this->addSql('DROP INDEX events_run_group_key_unique');
        $this->addSql('ALTER TABLE events DROP COLUMN post_text');
        $this->addSql('ALTER TABLE events DROP COLUMN group_key');
    }
}
