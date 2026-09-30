<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 40.1: the BK episodes of private-run slots, to notify the players when one ends.
 */
final class Version20260929100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 40.1: session_slot_block';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE session_slot_block (
            session_id VARCHAR(64) NOT NULL,
            slot_index VARCHAR(16) NOT NULL,
            slot_name VARCHAR(64) NOT NULL,
            blocked_since TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (session_id, slot_index)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE session_slot_block');
    }
}
