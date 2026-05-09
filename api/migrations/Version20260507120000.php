<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add runner_id, last_heartbeat_at, last_activity_at to archipelago_sessions (state guardrails)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE archipelago_sessions ADD runner_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE archipelago_sessions ADD last_heartbeat_at TIMESTAMPTZ DEFAULT NULL');
        $this->addSql('ALTER TABLE archipelago_sessions ADD last_activity_at TIMESTAMPTZ DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE archipelago_sessions DROP COLUMN runner_id');
        $this->addSql('ALTER TABLE archipelago_sessions DROP COLUMN last_heartbeat_at');
        $this->addSql('ALTER TABLE archipelago_sessions DROP COLUMN last_activity_at');
    }
}
