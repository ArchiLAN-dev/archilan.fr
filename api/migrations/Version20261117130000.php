<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Story 55.1: tracked links `/l/{code}` and their visits, counted per day and per host the visitor came from. */
final class Version20261117130000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Content: tracked links and their daily visit counts';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tracked_link (id VARCHAR(32) NOT NULL, code VARCHAR(40) NOT NULL, label VARCHAR(120) NOT NULL, target VARCHAR(500) NOT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_tracked_link_code ON tracked_link (code)');
        // No foreign key: a link is never deleted. The counts are written by one upsert, never by the ORM.
        $this->addSql('CREATE TABLE tracked_link_visit (link_id VARCHAR(32) NOT NULL, day DATE NOT NULL, referrer_host VARCHAR(253) NOT NULL, visits INT NOT NULL, PRIMARY KEY (link_id, day, referrer_host))');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tracked_link_visit');
        $this->addSql('DROP TABLE tracked_link');
    }
}
