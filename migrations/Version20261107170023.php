<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107170023 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Создание запусков дайджеста, материалов, событий, доказательств, файлов и отправок';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');

        $this->addSql(<<<'SQL'
            CREATE TABLE runs (
                id UUID NOT NULL PRIMARY KEY,
                control_time TIMESTAMPTZ NOT NULL,
                status TEXT NOT NULL,
                model_version TEXT NOT NULL,
                prompt_version TEXT NOT NULL,
                settings_snapshot JSONB NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                started_at TIMESTAMPTZ DEFAULT NULL,
                finished_at TIMESTAMPTZ DEFAULT NULL,
                published_at TIMESTAMPTZ DEFAULT NULL,
                error TEXT DEFAULT NULL,
                CONSTRAINT runs_status_check CHECK (status IN ('queued', 'running', 'completed', 'failed')),
                CONSTRAINT runs_settings_object_check CHECK (jsonb_typeof(settings_snapshot) = 'object'),
                CONSTRAINT runs_finished_after_started_check CHECK (finished_at IS NULL OR (started_at IS NOT NULL AND finished_at >= started_at)),
                CONSTRAINT runs_published_completed_check CHECK (published_at IS NULL OR status = 'completed')
            )
            SQL);
        $this->addSql('CREATE INDEX runs_published_at_idx ON runs (published_at DESC) WHERE published_at IS NOT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE run_inputs (
                id UUID NOT NULL PRIMARY KEY,
                run_id UUID NOT NULL REFERENCES runs (id),
                kind TEXT NOT NULL,
                name TEXT NOT NULL,
                locator TEXT NOT NULL,
                sha256 TEXT DEFAULT NULL,
                status TEXT NOT NULL,
                error TEXT DEFAULT NULL,
                CONSTRAINT run_inputs_kind_check CHECK (kind IN ('site', 'dataset')),
                CONSTRAINT run_inputs_sha256_check CHECK (
                    (kind = 'site' AND sha256 IS NULL)
                    OR (kind = 'dataset' AND sha256 IS NOT NULL AND sha256 ~ '^[0-9a-f]{64}$')
                ),
                CONSTRAINT run_inputs_status_check CHECK (status IN ('pending', 'completed', 'failed')),
                CONSTRAINT run_inputs_name_not_empty_check CHECK (name <> ''),
                CONSTRAINT run_inputs_locator_not_empty_check CHECK (locator <> ''),
                CONSTRAINT run_inputs_id_run_unique UNIQUE (id, run_id)
            )
            SQL);
        $this->addSql('CREATE INDEX run_inputs_run_id_idx ON run_inputs (run_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE materials (
                id UUID NOT NULL PRIMARY KEY,
                run_id UUID NOT NULL,
                run_input_id UUID NOT NULL,
                input_id TEXT NOT NULL,
                title TEXT DEFAULT NULL,
                body TEXT DEFAULT NULL,
                source_name TEXT DEFAULT NULL,
                url TEXT DEFAULT NULL,
                published_at TIMESTAMPTZ DEFAULT NULL,
                journal_status TEXT DEFAULT NULL,
                journal_reason TEXT DEFAULT NULL,
                journal_error TEXT DEFAULT NULL,
                CONSTRAINT materials_input_run_fk FOREIGN KEY (run_input_id, run_id) REFERENCES run_inputs (id, run_id),
                CONSTRAINT materials_input_id_not_empty_check CHECK (input_id <> ''),
                CONSTRAINT materials_journal_status_check CHECK (journal_status IN ('included', 'merged', 'excluded')),
                CONSTRAINT materials_journal_reason_check CHECK (journal_status IS NULL OR (journal_reason IS NOT NULL AND journal_reason <> '')),
                CONSTRAINT materials_input_id_unique UNIQUE (run_input_id, input_id),
                CONSTRAINT materials_id_run_unique UNIQUE (id, run_id)
            )
            SQL);
        $this->addSql('CREATE INDEX materials_input_published_at_idx ON materials (run_input_id, published_at)');
        $this->addSql('CREATE INDEX materials_run_id_idx ON materials (run_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE events (
                id UUID NOT NULL PRIMARY KEY,
                run_id UUID NOT NULL REFERENCES runs (id),
                rank SMALLINT NOT NULL,
                title TEXT NOT NULL,
                summary TEXT NOT NULL,
                consequences TEXT NOT NULL,
                selection_reason TEXT NOT NULL,
                new_facts_reason TEXT DEFAULT NULL,
                CONSTRAINT events_rank_check CHECK (rank BETWEEN 1 AND 5),
                CONSTRAINT events_title_not_empty_check CHECK (title <> ''),
                CONSTRAINT events_summary_not_empty_check CHECK (summary <> ''),
                CONSTRAINT events_consequences_not_empty_check CHECK (consequences <> ''),
                CONSTRAINT events_selection_reason_not_empty_check CHECK (selection_reason <> ''),
                CONSTRAINT events_run_rank_unique UNIQUE (run_id, rank),
                CONSTRAINT events_id_run_unique UNIQUE (id, run_id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE event_materials (
                event_id UUID NOT NULL,
                material_id UUID NOT NULL,
                run_id UUID NOT NULL,
                is_primary_source BOOLEAN NOT NULL DEFAULT FALSE,
                PRIMARY KEY (event_id, material_id),
                CONSTRAINT event_materials_event_run_fk FOREIGN KEY (event_id, run_id) REFERENCES events (id, run_id),
                CONSTRAINT event_materials_material_run_fk FOREIGN KEY (material_id, run_id) REFERENCES materials (id, run_id)
            )
            SQL);
        $this->addSql('CREATE INDEX event_materials_material_run_idx ON event_materials (material_id, run_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE event_evidence (
                id UUID NOT NULL PRIMARY KEY,
                event_id UUID NOT NULL,
                material_id UUID NOT NULL,
                claim TEXT NOT NULL,
                source_excerpt TEXT NOT NULL,
                qualification TEXT DEFAULT NULL,
                CONSTRAINT event_evidence_event_material_fk FOREIGN KEY (event_id, material_id)
                    REFERENCES event_materials (event_id, material_id),
                CONSTRAINT event_evidence_claim_not_empty_check CHECK (claim <> ''),
                CONSTRAINT event_evidence_excerpt_not_empty_check CHECK (source_excerpt <> '')
            )
            SQL);
        $this->addSql('CREATE INDEX event_evidence_event_material_idx ON event_evidence (event_id, material_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE artifacts (
                id UUID NOT NULL PRIMARY KEY,
                run_id UUID NOT NULL REFERENCES runs (id),
                kind TEXT NOT NULL,
                content TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT artifacts_kind_check CHECK (kind IN ('digest', 'post', 'email_preview', 'journal')),
                CONSTRAINT artifacts_run_kind_unique UNIQUE (run_id, kind)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE email_preview_details (
                artifact_id UUID NOT NULL PRIMARY KEY REFERENCES artifacts (id),
                subject TEXT NOT NULL,
                CONSTRAINT email_preview_details_subject_not_empty_check CHECK (subject <> '')
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE model_calls (
                id UUID NOT NULL PRIMARY KEY,
                run_id UUID NOT NULL REFERENCES runs (id),
                stage TEXT NOT NULL,
                input_tokens INTEGER DEFAULT NULL,
                output_tokens INTEGER DEFAULT NULL,
                cost NUMERIC(14, 6) DEFAULT NULL,
                cost_currency VARCHAR(3) DEFAULT NULL,
                duration_ms INTEGER DEFAULT NULL,
                error TEXT DEFAULT NULL,
                CONSTRAINT model_calls_stage_not_empty_check CHECK (stage <> ''),
                CONSTRAINT model_calls_input_tokens_check CHECK (input_tokens >= 0),
                CONSTRAINT model_calls_output_tokens_check CHECK (output_tokens >= 0),
                CONSTRAINT model_calls_cost_check CHECK (cost >= 0),
                CONSTRAINT model_calls_cost_currency_check CHECK (
                    (cost IS NULL AND cost_currency IS NULL)
                    OR (cost IS NOT NULL AND cost_currency IS NOT NULL AND cost_currency ~ '^[A-Z]{3}$')
                ),
                CONSTRAINT model_calls_duration_check CHECK (duration_ms >= 0)
            )
            SQL);
        $this->addSql('CREATE INDEX model_calls_run_id_idx ON model_calls (run_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE delivery_targets (
                id UUID NOT NULL PRIMARY KEY,
                channel TEXT NOT NULL,
                destination TEXT NOT NULL,
                status TEXT NOT NULL,
                confirmed_at TIMESTAMPTZ DEFAULT NULL,
                CONSTRAINT delivery_targets_channel_check CHECK (channel IN ('email', 'telegram')),
                CONSTRAINT delivery_targets_status_check CHECK (status IN ('pending', 'active', 'unsubscribed')),
                CONSTRAINT delivery_targets_destination_not_empty_check CHECK (destination <> ''),
                CONSTRAINT delivery_targets_confirmed_check CHECK (status <> 'active' OR confirmed_at IS NOT NULL),
                CONSTRAINT delivery_targets_channel_destination_unique UNIQUE (channel, destination)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE deliveries (
                id UUID NOT NULL PRIMARY KEY,
                artifact_id UUID NOT NULL REFERENCES artifacts (id),
                target_id UUID NOT NULL REFERENCES delivery_targets (id),
                status TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                provider_message_id TEXT DEFAULT NULL,
                last_error TEXT DEFAULT NULL,
                sent_at TIMESTAMPTZ DEFAULT NULL,
                CONSTRAINT deliveries_status_check CHECK (status IN ('queued', 'sent', 'failed')),
                CONSTRAINT deliveries_attempts_check CHECK (attempts >= 0),
                CONSTRAINT deliveries_sent_at_check CHECK (status <> 'sent' OR sent_at IS NOT NULL),
                CONSTRAINT deliveries_artifact_target_unique UNIQUE (artifact_id, target_id)
            )
            SQL);
        $this->addSql('CREATE INDEX deliveries_target_status_idx ON deliveries (target_id, status)');

        $comments = [
            'runs' => [
                'id' => 'Идентификатор одного запуска обработки дайджеста.',
                'control_time' => 'Конец окна отбора в 24 часа; публикация должна быть позже начала окна.',
                'status' => 'Состояние запуска: в очереди, выполняется, завершён или завершился ошибкой.',
                'model_version' => 'Версия модели ИИ, использованной в запуске.',
                'prompt_version' => 'Версия промптов запуска; их текст хранится под этой версией отдельно.',
                'settings_snapshot' => 'Снимок настроек модели и обработки без секретов на момент запуска.',
                'created_at' => 'Время создания запуска.',
                'started_at' => 'Время начала обработки; отсутствует у запуска в очереди.',
                'finished_at' => 'Время завершения обработки или ошибки.',
                'published_at' => 'Время публикации готового выпуска для читателей.',
                'error' => 'Причина ошибки всего запуска, а не отдельного материала.',
            ],
            'run_inputs' => [
                'id' => 'Идентификатор сайта или набора организаторов в запуске.',
                'run_id' => 'Запуск, обрабатывающий этот вход.',
                'kind' => 'Вид входа: сайт или набор организаторов.',
                'name' => 'Понятное название сайта или набора.',
                'locator' => 'URL сайта или ссылка на сохранённый исходный файл набора.',
                'sha256' => 'Контрольная сумма исходного файла; для сайта отсутствует.',
                'status' => 'Состояние чтения входа: ожидает, прочитан или ошибка.',
                'error' => 'Причина ошибки чтения всего сайта или набора.',
            ],
            'materials' => [
                'id' => 'Идентификатор снимка одной входной публикации.',
                'run_id' => 'Запуск для составных внешних ключей; обязан совпадать с запуском входа.',
                'run_input_id' => 'Сайт или набор, из которого получена публикация.',
                'input_id' => 'Исходный ID материала из набора или стабильный ID, назначенный при сборе сайта.',
                'title' => 'Исходный заголовок публикации, если доступен.',
                'body' => 'Исходный текст публикации; считается недоверенными данными.',
                'source_name' => 'Название издателя из исходной публикации.',
                'url' => 'Исходный URL публикации, если доступен.',
                'published_at' => 'Подтверждённое время публикации; отсутствует, если оно неизвестно.',
                'journal_status' => 'Решение журнала: включён, объединён или исключён; во время обработки отсутствует.',
                'journal_reason' => 'Объяснение включения, объединения или исключения материала.',
                'journal_error' => 'Ошибка чтения или обработки отдельной публикации.',
            ],
            'events' => [
                'id' => 'Идентификатор одного выбранного события.',
                'run_id' => 'Запуск, в котором выбрано событие.',
                'rank' => 'Позиция события в дайджесте от одного до пяти.',
                'title' => 'Нейтральный заголовок события для дайджеста.',
                'summary' => 'Краткое описание того, что произошло.',
                'consequences' => 'Затронутые организации и последствия либо явное отсутствие таких сведений.',
                'selection_reason' => 'Обоснование выбора и порядка события фактами из источников.',
                'new_facts_reason' => 'Новые факты, оправдывающие повторное включение старого события.',
            ],
            'event_materials' => [
                'event_id' => 'Событие, описанное публикацией.',
                'material_id' => 'Публикация, описывающая событие.',
                'run_id' => 'Общий запуск события и материала, проверяемый внешними ключами.',
                'is_primary_source' => 'Является ли публикация первичным источником среди входных материалов.',
            ],
            'event_evidence' => [
                'id' => 'Идентификатор связи конкретного факта с источником.',
                'event_id' => 'Событие, к которому относится подтверждаемый факт.',
                'material_id' => 'Материал с фрагментом, подтверждающим факт.',
                'claim' => 'Конкретное утверждение, которое нужно сверить с источником.',
                'source_excerpt' => 'Исходный фрагмент, подтверждающий или уточняющий утверждение.',
                'qualification' => 'Оговорка источника, например предварительный характер сведений.',
            ],
            'artifacts' => [
                'id' => 'Идентификатор одного сформированного файла.',
                'run_id' => 'Запуск, создавший файл.',
                'kind' => 'Вид результата: дайджест, пост, предпросмотр письма или журнал.',
                'content' => 'Зафиксированный текст для скачивания или отправки.',
                'created_at' => 'Время формирования текста файла.',
            ],
            'email_preview_details' => [
                'artifact_id' => 'Файл предпросмотра письма, для которого сохранена тема.',
                'subject' => 'Тема письма для предпросмотра и отправки.',
            ],
            'model_calls' => [
                'id' => 'Идентификатор одного обращения к модели ИИ.',
                'run_id' => 'Запуск, в котором выполнено обращение.',
                'stage' => 'Этап обработки, вызвавший модель.',
                'input_tokens' => 'Число входных токенов по ответу провайдера, если известно.',
                'output_tokens' => 'Число выходных токенов по ответу провайдера, если известно.',
                'cost' => 'Фактическая или оценочная стоимость вызова, если известна.',
                'cost_currency' => 'Трёхбуквенный код валюты стоимости.',
                'duration_ms' => 'Длительность обращения в миллисекундах, если измерена.',
                'error' => 'Причина ошибки отдельного обращения к модели.',
            ],
            'delivery_targets' => [
                'id' => 'Идентификатор адреса почты или чата Telegram.',
                'channel' => 'Канал отправки: почта или Telegram.',
                'destination' => 'Нормализованный адрес или ID чата; хранится отдельно от текста новостей.',
                'status' => 'Состояние подписки: ожидает, активна или отменена.',
                'confirmed_at' => 'Время подтверждения подписки получателем.',
            ],
            'deliveries' => [
                'id' => 'Идентификатор одной отправки файла одному получателю.',
                'artifact_id' => 'Зафиксированный файл, отправляемый получателю.',
                'target_id' => 'Получатель файла.',
                'status' => 'Состояние отправки: в очереди, отправлена или ошибка.',
                'attempts' => 'Число выполненных попыток отправки.',
                'provider_message_id' => 'ID сообщения у почтового провайдера или Telegram.',
                'last_error' => 'Последняя ошибка провайдера при отправке.',
                'sent_at' => 'Время подтверждения успешной отправки провайдером.',
            ],
        ];

        foreach ($comments as $table => $columns) {
            $this->addSql(sprintf("COMMENT ON TABLE %s IS 'Таблица %s для сервиса дайджестов.'", $table, $table));

            foreach ($columns as $column => $description) {
                $this->addSql(sprintf("COMMENT ON COLUMN %s.%s IS '%s'", $table, $column, $description));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');

        $this->addSql('DROP TABLE deliveries');
        $this->addSql('DROP TABLE delivery_targets');
        $this->addSql('DROP TABLE model_calls');
        $this->addSql('DROP TABLE email_preview_details');
        $this->addSql('DROP TABLE artifacts');
        $this->addSql('DROP TABLE event_evidence');
        $this->addSql('DROP TABLE event_materials');
        $this->addSql('DROP TABLE events');
        $this->addSql('DROP TABLE materials');
        $this->addSql('DROP TABLE run_inputs');
        $this->addSql('DROP TABLE runs');
    }
}
