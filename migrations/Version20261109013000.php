<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109013000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Сохраняет номер следующего сообщения в многочастной доставке Telegram';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deliveries ADD next_part INTEGER NOT NULL DEFAULT 0 CHECK (next_part >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deliveries DROP COLUMN next_part');
    }
}
