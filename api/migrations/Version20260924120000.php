<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 38.1: apworld incidents.
 *
 * The partial unique index is what guarantees one active incident per (game, apworld hash, type)
 * even when two reconciliations run at once. It is also declared on the entity, in the form
 * PostgreSQL normalizes it to, so `doctrine:migrations:diff` does not offer to drop it.
 *
 * No foreign key to `game` on purpose: an incident is history. When a game loses its apworld or is
 * removed, the reconciliation resolves the incident instead of the database deleting it.
 */
final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create apworld_incident (story 38.1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE apworld_incident (
            id VARCHAR(32) NOT NULL,
            game_id VARCHAR(32) NOT NULL,
            apworld_hash VARCHAR(64) NOT NULL,
            type VARCHAR(32) NOT NULL,
            status VARCHAR(16) NOT NULL,
            error TEXT NOT NULL,
            opened_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            last_seen_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            occurrences INT NOT NULL,
            acknowledged_by VARCHAR(32) DEFAULT NULL,
            acknowledged_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            closed_by VARCHAR(32) DEFAULT NULL,
            last_observation VARCHAR(64) DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_apworld_incident_status ON apworld_incident (status)');
        $this->addSql("CREATE UNIQUE INDEX uniq_apworld_incident_active_key ON apworld_incident (game_id, apworld_hash, type) WHERE status IN ('open', 'acknowledged')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE apworld_incident');
    }
}
