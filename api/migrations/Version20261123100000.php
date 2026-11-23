<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 56.3: the last dry generation of an event or a private run, and its balance measures.
 */
final class Version20261123100000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Schema: balance_report';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE balance_report (id VARCHAR(80) NOT NULL, scope_type VARCHAR(16) NOT NULL, scope_id VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, requested_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, slots JSON NOT NULL, fingerprint VARCHAR(64) NOT NULL, measures JSON DEFAULT NULL, finished_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, error TEXT DEFAULT NULL, output_key VARCHAR(255) DEFAULT NULL, missing_files JSON NOT NULL, PRIMARY KEY (id))');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE balance_report');
    }
}
