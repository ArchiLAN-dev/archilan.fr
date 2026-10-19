<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 38.18: the passes checking the apworlds for updates, queued by the catalogue page or run at night.
 */
final class Version20261019100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 38.18: apworld_update_check_run';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE apworld_update_check_run (
            id VARCHAR(32) NOT NULL,
            trigger_source VARCHAR(16) NOT NULL,
            status VARCHAR(16) NOT NULL,
            active_slot VARCHAR(8) DEFAULT NULL,
            queued_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            progressed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            finished_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            total INT DEFAULT NULL,
            checked INT NOT NULL,
            failed INT NOT NULL,
            rate_limit_hit BOOLEAN NOT NULL,
            updates_available INT NOT NULL,
            error TEXT DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_apworld_update_check_run_active ON apworld_update_check_run (active_slot)');
        $this->addSql('CREATE INDEX idx_apworld_update_check_run_queued_at ON apworld_update_check_run (queued_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE apworld_update_check_run');
    }
}
