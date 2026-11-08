<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108174218 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Каталог сайтов задания и связь выбранного сайта с входом запуска';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');

        $this->addSql(<<<'SQL'
            CREATE TABLE site_sources (
                id UUID NOT NULL PRIMARY KEY,
                name TEXT NOT NULL,
                task_url TEXT NOT NULL,
                search_domain TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'candidate',
                CONSTRAINT site_sources_name_not_empty_check CHECK (name <> ''),
                CONSTRAINT site_sources_task_url_https_check CHECK (task_url LIKE 'https://%'),
                CONSTRAINT site_sources_search_domain_check CHECK (search_domain ~ '^[a-z0-9.-]+\.[a-z]{2,}$'),
                CONSTRAINT site_sources_status_check CHECK (status IN ('candidate', 'ready', 'disabled')),
                CONSTRAINT site_sources_task_url_unique UNIQUE (task_url)
            )
            SQL);
        $this->addSql('CREATE INDEX site_sources_status_name_idx ON site_sources (status, name)');
        $this->addSql('ALTER TABLE run_inputs ADD COLUMN site_source_id UUID DEFAULT NULL REFERENCES site_sources (id)');
        $this->addSql('CREATE INDEX run_inputs_site_source_id_idx ON run_inputs (site_source_id) WHERE site_source_id IS NOT NULL');

        $this->addSql(<<<'SQL'
            INSERT INTO site_sources (id, name, task_url, search_domain) VALUES
                (gen_random_uuid(), 'SecurityLab', 'https://www.securitylab.ru', 'securitylab.ru'),
                (gen_random_uuid(), 'Хакер', 'https://xakep.ru', 'xakep.ru'),
                (gen_random_uuid(), 'ComNews', 'https://www.comnews.ru/information_security', 'comnews.ru'),
                (gen_random_uuid(), 'Коммерсантъ', 'https://www.kommersant.ru/theme/1267', 'kommersant.ru'),
                (gen_random_uuid(), 'ТАСС', 'https://tass.ru/', 'tass.ru'),
                (gen_random_uuid(), 'РИА Новости', 'https://ria.ru/kiberbezopasnost', 'ria.ru'),
                (gen_random_uuid(), 'IT-World', 'https://www.it-world.ru/security/', 'it-world.ru'),
                (gen_random_uuid(), 'SecurityMedia', 'https://securitymedia.org', 'securitymedia.org'),
                (gen_random_uuid(), 'InfoWatch', 'https://www.infowatch.ru/analytics/novosti-ib', 'infowatch.ru'),
                (gen_random_uuid(), 'Инфобезопасность', 'https://infobezopasnost.ru/blog/news/', 'infobezopasnost.ru'),
                (gen_random_uuid(), 'ICT.Moscow', 'https://ict.moscow/projects/cybersecurity/', 'ict.moscow'),
                (gen_random_uuid(), 'Газета.Ru', 'https://www.gazeta.ru/tech', 'gazeta.ru'),
                (gen_random_uuid(), 'Аргументы и факты', 'https://aif.ru/techno/web', 'aif.ru'),
                (gen_random_uuid(), 'CNews', 'https://safe.cnews.ru', 'safe.cnews.ru'),
                (gen_random_uuid(), 'Хабр', 'https://habr.com/ru/hubs/infosecurity/', 'habr.com'),
                (gen_random_uuid(), 'RB.RU', 'https://rb.ru/tag/cybersecurity/', 'rb.ru'),
                (gen_random_uuid(), '3DNews', 'https://3dnews.ru/security', '3dnews.ru'),
                (gen_random_uuid(), 'Компьютерра', 'https://www.computerra.ru/tag/security/', 'computerra.ru'),
                (gen_random_uuid(), 'ITWeek', 'https://www.itweek.ru/security/', 'itweek.ru'),
                (gen_random_uuid(), 'iXBT', 'https://www.ixbt.com/live/security/', 'ixbt.com'),
                (gen_random_uuid(), 'Overclockers', 'https://overclockers.ru/tag/security', 'overclockers.ru'),
                (gen_random_uuid(), 'Российская газета', 'https://rg.ru/theme/technologies/internet/kiberbezopasnost/', 'rg.ru'),
                (gen_random_uuid(), 'Известия', 'https://iz.ru/tag/kiberbezopasnost', 'iz.ru'),
                (gen_random_uuid(), 'Positive Technologies', 'https://ptsecurity.com/research/analytics/', 'ptsecurity.com'),
                (gen_random_uuid(), 'Солар', 'https://rt-solar.ru/events/news/', 'rt-solar.ru'),
                (gen_random_uuid(), 'Лаборатория Касперского', 'https://www.kaspersky.ru/about/press-releases', 'kaspersky.ru'),
                (gen_random_uuid(), 'UserGate', 'https://www.usergate.com/ru', 'usergate.com'),
                (gen_random_uuid(), 'ИнфоТеКС', 'https://infotecs.ru', 'infotecs.ru')
            SQL);

        $this->addSql("COMMENT ON TABLE site_sources IS 'Каталог сайтов из задания; candidate не означает проверенный источник.'");
        $this->addSql("COMMENT ON COLUMN site_sources.id IS 'Стабильный идентификатор выбора сайта в административном API.'");
        $this->addSql("COMMENT ON COLUMN site_sources.name IS 'Название источника из задания.'");
        $this->addSql("COMMENT ON COLUMN site_sources.task_url IS 'Исходный URL сайта или раздела из задания.'");
        $this->addSql("COMMENT ON COLUMN site_sources.search_domain IS 'Домен, который Symfony передаёт Go для поиска; путь из task_url не включён.'");
        $this->addSql("COMMENT ON COLUMN site_sources.status IS 'candidate: не проверен; ready: разрешён к выбору; disabled: отключён.'");
        $this->addSql("COMMENT ON COLUMN run_inputs.site_source_id IS 'Выбранный сайт каталога; NULL для файла и старых запусков. name и locator сохраняют снимок.'");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');

        $this->addSql('DROP INDEX run_inputs_site_source_id_idx');
        $this->addSql('ALTER TABLE run_inputs DROP COLUMN site_source_id');
        $this->addSql('DROP TABLE site_sources');
    }
}
