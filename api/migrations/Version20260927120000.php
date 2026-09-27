<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 39.1: one moderation case per member, mirrored by one post in the staff forum on Discord.
 */
final class Version20260927120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 39.1: moderation_case';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE moderation_case (
            id VARCHAR(32) NOT NULL,
            target_user_id VARCHAR(32) NOT NULL,
            status VARCHAR(16) NOT NULL,
            forum_thread_id VARCHAR(32) DEFAULT NULL,
            opened_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_moderation_case_target ON moderation_case (target_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE moderation_case');
    }
}
