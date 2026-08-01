<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Persist the live game feed (story 32.6): one row per item event, so a run's timeline and per-player
 * check curves can be replayed after the game, not only watched live.
 */
final class Version20260726191701 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create session_feed_event for the persisted game feed (story 32.6)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE session_feed_event (
                id VARCHAR(32) NOT NULL,
                session_id VARCHAR(64) NOT NULL,
                type VARCHAR(32) NOT NULL,
                text TEXT NOT NULL,
                occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                item_id INT DEFAULT NULL,
                item_name VARCHAR(255) DEFAULT NULL,
                location_id INT DEFAULT NULL,
                location_name VARCHAR(255) DEFAULT NULL,
                sender_slot INT DEFAULT NULL,
                sender_name VARCHAR(255) DEFAULT NULL,
                sender_game VARCHAR(255) DEFAULT NULL,
                receiver_slot INT DEFAULT NULL,
                receiver_name VARCHAR(255) DEFAULT NULL,
                receiver_game VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_feed_event_session_time ON session_feed_event (session_id, occurred_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE session_feed_event');
    }
}
