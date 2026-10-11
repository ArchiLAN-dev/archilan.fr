<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 43.27: the Discord message telling each run listing.
 */
final class Version20261011100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 43.27: personal_run_listing_post';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE personal_run_listing_post (personal_run_id VARCHAR(32) NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, message_id VARCHAR(32) DEFAULT NULL, fingerprint VARCHAR(64) DEFAULT NULL, mentioned_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(personal_run_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE personal_run_listing_post');
    }
}
