<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 39.2: the messages of a member's moderation case.
 */
final class Version20260927180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 39.2: moderation_case_message';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE moderation_case_message (
            id VARCHAR(32) NOT NULL,
            case_id VARCHAR(32) NOT NULL,
            author_user_id VARCHAR(32) NOT NULL,
            author_role VARCHAR(16) NOT NULL,
            body TEXT NOT NULL,
            source VARCHAR(16) NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX idx_moderation_case_message_case ON moderation_case_message (case_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE moderation_case_message');
    }
}
