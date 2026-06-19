<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 31.8 - single-row Archipelago client info (version + download URL).
 */
final class Version20260619100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create archipelago_client_info (Archipelago client version + download URL, story 31.8).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE archipelago_client_info (id VARCHAR(32) NOT NULL, version VARCHAR(50) NOT NULL, download_url VARCHAR(500) NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE archipelago_client_info');
    }
}
