<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 43.12: nudges towards a player of a personal run - the last one (shared cap) and the player's opt-out.
 */
final class Version20261010120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 43.12: personal_run_nudge';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE personal_run_nudge (id VARCHAR(32) NOT NULL, personal_run_id VARCHAR(32) NOT NULL, recipient_id VARCHAR(32) NOT NULL, muted BOOLEAN DEFAULT false NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_sender_id VARCHAR(32) DEFAULT NULL, last_nudged_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_personal_run_nudge ON personal_run_nudge (personal_run_id, recipient_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE personal_run_nudge');
    }
}
