<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 31.3 - single-row generic "Installer Archipelago" guide (ordered steps).
 */
final class Version20260619110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create archipelago_guide (generic install guide steps, story 31.3).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE archipelago_guide (id VARCHAR(32) NOT NULL, steps JSON NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE archipelago_guide');
    }
}
