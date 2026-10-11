<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 43.1: friends invited by name into a personal run.
 */
final class Version20261009110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 43.1: personal_run_invitation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE personal_run_invitation (id VARCHAR(32) NOT NULL, personal_run_id VARCHAR(32) NOT NULL, invitee_id VARCHAR(32) NOT NULL, inviter_id VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, invited_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, responded_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_personal_run_invitation ON personal_run_invitation (personal_run_id, invitee_id)');
        $this->addSql('CREATE INDEX idx_personal_run_invitation_invitee ON personal_run_invitation (invitee_id, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE personal_run_invitation');
    }
}
