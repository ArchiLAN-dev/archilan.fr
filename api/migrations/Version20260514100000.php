<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add last_save_key and paused_without_save to archipelago_sessions (inactivity watchdog)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE archipelago_sessions ADD last_save_key VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE archipelago_sessions ADD paused_without_save BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE archipelago_sessions DROP COLUMN last_save_key');
        $this->addSql('ALTER TABLE archipelago_sessions DROP COLUMN paused_without_save');
    }
}
