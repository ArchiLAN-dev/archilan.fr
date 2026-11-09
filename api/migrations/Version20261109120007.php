<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Story 52.6: the departures to handle - a player left, their world waits for a release. */
final class Version20261109120007 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Departure requests';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE departure_request (id VARCHAR(32) NOT NULL, session_id VARCHAR(64) NOT NULL, slot_index INT NOT NULL, slot_name VARCHAR(100) NOT NULL, origin VARCHAR(10) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, status VARCHAR(10) NOT NULL, closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, closed_by VARCHAR(32) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql("CREATE UNIQUE INDEX uniq_departure_request_open ON departure_request (session_id, slot_name) WHERE status = 'open'");
        $this->addSql('CREATE INDEX idx_departure_request_session ON departure_request (session_id, status)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE departure_request');
    }
}
