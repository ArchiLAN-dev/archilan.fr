<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 45.1: the partners of the association.
 */
final class Version20261025200004 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 45.1: partner';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE partner (id VARCHAR(32) NOT NULL, name VARCHAR(80) NOT NULL, description VARCHAR(160) NOT NULL, url VARCHAR(500) NOT NULL, active BOOLEAN NOT NULL, position INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, logo_key VARCHAR(500) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_partner_position ON partner (position)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE partner');
    }
}
