<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create personal_run_participants table for invite join tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE personal_run_participants (
            personal_run_id VARCHAR(32) NOT NULL,
            user_id VARCHAR(32) NOT NULL,
            joined_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (personal_run_id, user_id)
        )');
        $this->addSql('CREATE INDEX idx_prp_run ON personal_run_participants (personal_run_id)');
        $this->addSql('CREATE INDEX idx_prp_user ON personal_run_participants (user_id)');
        $this->addSql('ALTER TABLE personal_run_participants ADD CONSTRAINT fk_prp_run FOREIGN KEY (personal_run_id) REFERENCES personal_runs (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE personal_run_participants ADD CONSTRAINT fk_prp_user FOREIGN KEY (user_id) REFERENCES identity_users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE personal_run_participants DROP CONSTRAINT fk_prp_user');
        $this->addSql('ALTER TABLE personal_run_participants DROP CONSTRAINT fk_prp_run');
        $this->addSql('DROP TABLE personal_run_participants');
    }
}
