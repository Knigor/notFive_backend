<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108180308 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Администраторы для входа в GraphQL без публичной регистрации';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');

        $this->addSql(<<<'SQL'
            CREATE TABLE admin_users (
                id UUID NOT NULL PRIMARY KEY,
                username VARCHAR(64) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT admin_users_username_check CHECK (username ~ '^[a-z][a-z0-9_]{2,63}$'),
                CONSTRAINT admin_users_password_hash_check CHECK (password_hash <> '')
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX admin_users_username_unique_idx ON admin_users (username)');
        $this->addSql("COMMENT ON TABLE admin_users IS 'Администраторы с доступом к управлению запусками; публичной регистрации нет.'");
        $this->addSql("COMMENT ON COLUMN admin_users.id IS 'Стабильный идентификатор администратора для JWT и аудита.'");
        $this->addSql("COMMENT ON COLUMN admin_users.username IS 'Логин администратора: латинские строчные буквы, цифры и подчёркивание; уникален.'");
        $this->addSql("COMMENT ON COLUMN admin_users.password_hash IS 'Хеш пароля; открытый пароль в базе не хранится.'");
        $this->addSql("COMMENT ON COLUMN admin_users.is_active IS 'Разрешён ли вход и использование уже выданного токена.'");
        $this->addSql("COMMENT ON COLUMN admin_users.created_at IS 'Время создания учётной записи с часовым поясом.'");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'This migration requires PostgreSQL.');
        $this->addSql('DROP TABLE admin_users');
    }
}
