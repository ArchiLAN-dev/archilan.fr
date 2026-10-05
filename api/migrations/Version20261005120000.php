<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 16.21: a member archives a personal run in their own list.
 */
final class Version20261005120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 16.21: personal_run_archive';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE personal_run_archive (personal_run_id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, archived_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (personal_run_id, user_id))');
        $this->addSql('CREATE INDEX idx_personal_run_archive_user ON personal_run_archive (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE personal_run_archive');
    }
}
