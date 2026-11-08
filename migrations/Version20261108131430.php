<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108131430 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Сохранение исходных признаков набора и ошибок отдельных строк при импорте';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');

        $this->addSql('ALTER TABLE runs ALTER COLUMN model_version DROP NOT NULL');
        $this->addSql('ALTER TABLE runs ALTER COLUMN prompt_version DROP NOT NULL');
        $this->addSql("ALTER TABLE materials ADD COLUMN input_missing JSONB NOT NULL DEFAULT '[]'::jsonb");
        $this->addSql('ALTER TABLE materials ADD COLUMN source_error TEXT DEFAULT NULL');
        $this->addSql("ALTER TABLE materials ADD CONSTRAINT materials_input_missing_array_check CHECK (jsonb_typeof(input_missing) = 'array')");
        $this->addSql(<<<'SQL'
            CREATE TABLE dataset_import_errors (
                id UUID NOT NULL PRIMARY KEY,
                run_input_id UUID NOT NULL REFERENCES run_inputs (id),
                row_number INTEGER NOT NULL,
                input_id TEXT DEFAULT NULL,
                error TEXT NOT NULL,
                CONSTRAINT dataset_import_errors_row_positive_check CHECK (row_number > 0),
                CONSTRAINT dataset_import_errors_error_not_empty_check CHECK (error <> ''),
                CONSTRAINT dataset_import_errors_row_unique UNIQUE (run_input_id, row_number)
            )
            SQL);
        $this->addSql('CREATE INDEX dataset_import_errors_input_idx ON dataset_import_errors (run_input_id)');
        $this->addSql("COMMENT ON COLUMN runs.model_version IS 'Версия модели ИИ; отсутствует до запуска анализа.'");
        $this->addSql("COMMENT ON COLUMN runs.prompt_version IS 'Версия промптов; отсутствует до запуска анализа.'");
        $this->addSql("COMMENT ON COLUMN materials.input_missing IS 'Имена отсутствующих полей, заявленные исходным набором.'");
        $this->addSql("COMMENT ON COLUMN materials.source_error IS 'Ошибка получения публикации, записанная в исходном наборе; не решение журнала дайджеста.'");
        $this->addSql("COMMENT ON TABLE dataset_import_errors IS 'Ошибки отдельных строк набора, которые не остановили импорт остальных строк.'");
        $this->addSql("COMMENT ON COLUMN dataset_import_errors.id IS 'Идентификатор ошибки импорта строки.'");
        $this->addSql("COMMENT ON COLUMN dataset_import_errors.run_input_id IS 'Исходный файл, в котором находится строка.'");
        $this->addSql("COMMENT ON COLUMN dataset_import_errors.row_number IS 'Порядковый номер публикации в наборе, начиная с единицы.'");
        $this->addSql("COMMENT ON COLUMN dataset_import_errors.input_id IS 'Исходный ID, если его удалось прочитать.'");
        $this->addSql("COMMENT ON COLUMN dataset_import_errors.error IS 'Причина отказа в импорте отдельной публикации.'");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');

        $this->addSql('DROP TABLE dataset_import_errors');
        $this->addSql('ALTER TABLE materials DROP CONSTRAINT materials_input_missing_array_check');
        $this->addSql('ALTER TABLE materials DROP COLUMN source_error');
        $this->addSql('ALTER TABLE materials DROP COLUMN input_missing');
        $this->addSql("UPDATE runs SET model_version = 'not_selected' WHERE model_version IS NULL");
        $this->addSql("UPDATE runs SET prompt_version = 'not_selected' WHERE prompt_version IS NULL");
        $this->addSql('ALTER TABLE runs ALTER COLUMN model_version SET NOT NULL');
        $this->addSql('ALTER TABLE runs ALTER COLUMN prompt_version SET NOT NULL');
    }
}
