<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 46.1: admin notes on a target, their follow-ups and the previous texts of an edited note.
 */
final class Version20261025200008 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 46.1: admin_note, admin_note_follow_up, admin_note_revision';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_note (id VARCHAR(32) NOT NULL, target_type VARCHAR(32) NOT NULL, target_id VARCHAR(64) NOT NULL, body TEXT NOT NULL, status VARCHAR(16) NOT NULL, author_id VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_admin_note_target ON admin_note (target_type, target_id, status)');
        $this->addSql('CREATE TABLE admin_note_follow_up (id VARCHAR(32) NOT NULL, note_id VARCHAR(32) NOT NULL, kind VARCHAR(16) NOT NULL, body TEXT NOT NULL, author_id VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_admin_note_follow_up_note ON admin_note_follow_up (note_id, created_at)');
        $this->addSql('CREATE TABLE admin_note_revision (id VARCHAR(32) NOT NULL, note_id VARCHAR(32) NOT NULL, previous_body TEXT NOT NULL, editor_id VARCHAR(32) NOT NULL, edited_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_admin_note_revision_note ON admin_note_revision (note_id, edited_at)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_note_revision');
        $this->addSql('DROP TABLE admin_note_follow_up');
        $this->addSql('DROP TABLE admin_note');
    }
}
