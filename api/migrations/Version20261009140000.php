<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 43.10: the BK a slot got out of, kept for the recap's « sorti du BK ».
 */
final class Version20261009140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 43.10: session_slot_block_release';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE session_slot_block_release (id VARCHAR(32) NOT NULL, session_id VARCHAR(64) NOT NULL, slot_name VARCHAR(64) NOT NULL, blocked_since TIMESTAMP(0) WITH TIME ZONE NOT NULL, released_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_slot_block_release_session ON session_slot_block_release (session_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE session_slot_block_release');
    }
}
