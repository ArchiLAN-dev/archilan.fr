<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 56.7: the size options of a game, and each player's last search for a value that fits the target.
 */
final class Version20261123100004 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Schema: game.size_options, size_search';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD size_options JSON DEFAULT NULL');
        $this->addSql('CREATE TABLE size_search (id VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL, game_id VARCHAR(64) NOT NULL, round VARCHAR(32) NOT NULL, yaml TEXT NOT NULL, target_hours DOUBLE PRECISION NOT NULL, status VARCHAR(16) NOT NULL, options JSON NOT NULL, plan JSON NOT NULL, tries JSON NOT NULL, started_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, pending JSON DEFAULT NULL, proposal JSON DEFAULT NULL, error TEXT DEFAULT NULL, PRIMARY KEY (id))');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE size_search');
        $this->addSql('ALTER TABLE game DROP size_options');
    }
}
