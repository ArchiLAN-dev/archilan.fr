<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 38.6: apworld candidates.
 *
 * A new apworld version is tested before it serves players; the candidate is that version while it
 * waits for its verdict. Every candidate is kept, promoted or not: the history is what stops the
 * nightly update from retrying a version that was rejected.
 *
 * No foreign key to `game`, like `apworld_incident`: history outlives the game it describes.
 */
final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create apworld_candidate (story 38.6)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE apworld_candidate (
            id VARCHAR(32) NOT NULL,
            game_id VARCHAR(32) NOT NULL,
            apworld_hash VARCHAR(64) NOT NULL,
            storage_key VARCHAR(255) NOT NULL,
            minio_key VARCHAR(255) NOT NULL,
            default_yaml TEXT NOT NULL,
            archipelago_game_name VARCHAR(255) NOT NULL,
            version_tag VARCHAR(100) DEFAULT NULL,
            origin VARCHAR(16) NOT NULL,
            submitted_by VARCHAR(32) DEFAULT NULL,
            submitted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            status VARCHAR(16) NOT NULL,
            decided_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            forced_by VARCHAR(32) DEFAULT NULL,
            rejection_reason TEXT DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_apworld_candidate_game_status ON apworld_candidate (game_id, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE apworld_candidate');
    }
}
