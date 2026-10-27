<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 32.26: the stretches of time a game client held a slot, counted from the bridge's heartbeat.
 */
final class Version20261025200002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 32.26: session_slot_play_span';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE session_slot_play_span (id VARCHAR(32) NOT NULL, session_id VARCHAR(64) NOT NULL, slot_name VARCHAR(64) NOT NULL, started_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_slot_play_span_session_slot ON session_slot_play_span (session_id, slot_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE session_slot_play_span');
    }
}
