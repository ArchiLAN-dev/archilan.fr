<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.1: the pelles ledger, one append-only line per movement.
 */
final class Version20261003100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.1: pelle_movement ledger';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pelle_movement (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, amount INT NOT NULL, kind VARCHAR(10) NOT NULL, event_id VARCHAR(32) DEFAULT NULL, reason VARCHAR(40) NOT NULL, label VARCHAR(200) NOT NULL, author_id VARCHAR(32) DEFAULT NULL, unique_key VARCHAR(120) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_pelle_movement_user ON pelle_movement (user_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_pelle_movement_key ON pelle_movement (unique_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pelle_movement');
    }
}
