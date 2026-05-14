<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create personal_runs table for user-owned private Archipelago games.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE personal_runs (
            id VARCHAR(32) NOT NULL,
            owner_id VARCHAR(32) NOT NULL,
            title VARCHAR(120) NOT NULL,
            status VARCHAR(20) NOT NULL,
            invite_token VARCHAR(64) NOT NULL,
            game_selection_config JSON DEFAULT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_personal_runs_invite_token ON personal_runs (invite_token)');
        $this->addSql('CREATE INDEX idx_personal_runs_owner_id ON personal_runs (owner_id)');
        $this->addSql('ALTER TABLE personal_runs ADD CONSTRAINT fk_personal_runs_owner FOREIGN KEY (owner_id) REFERENCES identity_users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE personal_runs DROP CONSTRAINT fk_personal_runs_owner');
        $this->addSql('DROP TABLE personal_runs');
    }
}
