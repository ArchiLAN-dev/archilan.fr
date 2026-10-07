<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.4: bounties on items, paid in pelles.
 */
final class Version20261003150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.4: item_bounty';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE item_bounty (id VARCHAR(32) NOT NULL, session_id VARCHAR(64) NOT NULL, slot_name VARCHAR(255) NOT NULL, item_name VARCHAR(255) NOT NULL, amount INT NOT NULL, poster_id VARCHAR(32) NOT NULL, status VARCHAR(12) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, settled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, winner_id VARCHAR(32) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_item_bounty_session ON item_bounty (session_id, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE item_bounty');
    }
}
