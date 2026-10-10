<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 43.15: duels between friends on a weekly run, and who is in each.
 */
final class Version20261010150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 43.15: weekly_duel, weekly_duel_participant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE weekly_duel (id VARCHAR(32) NOT NULL, weekly_run_id VARCHAR(36) NOT NULL, creator_id VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, resolved_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, winner_id VARCHAR(32) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_weekly_duel_run ON weekly_duel (weekly_run_id)');
        $this->addSql('CREATE INDEX idx_weekly_duel_creator ON weekly_duel (creator_id, created_at)');
        $this->addSql('CREATE TABLE weekly_duel_participant (id VARCHAR(32) NOT NULL, duel_id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, invited_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, responded_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_weekly_duel_participant ON weekly_duel_participant (duel_id, user_id)');
        $this->addSql('CREATE INDEX idx_weekly_duel_participant_user ON weekly_duel_participant (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE weekly_duel_participant');
        $this->addSql('DROP TABLE weekly_duel');
    }
}
