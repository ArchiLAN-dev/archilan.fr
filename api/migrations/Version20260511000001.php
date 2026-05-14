<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add APWorld community catalogue metadata fields to games table (story 14.1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE games ADD adult_content BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE games ADD bundled_with_ap BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE games ADD availability_locked BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE games ADD catalog_sheet_name VARCHAR(160) DEFAULT NULL');
        $this->addSql('ALTER TABLE games ADD apworld_source_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE games ADD apworld_deployed_version VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE games ADD apworld_latest_version VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE games ADD apworld_checked_at TIMESTAMPTZ DEFAULT NULL');
        $this->addSql('ALTER TABLE games ADD igdb_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE games DROP adult_content');
        $this->addSql('ALTER TABLE games DROP bundled_with_ap');
        $this->addSql('ALTER TABLE games DROP availability_locked');
        $this->addSql('ALTER TABLE games DROP catalog_sheet_name');
        $this->addSql('ALTER TABLE games DROP apworld_source_url');
        $this->addSql('ALTER TABLE games DROP apworld_deployed_version');
        $this->addSql('ALTER TABLE games DROP apworld_latest_version');
        $this->addSql('ALTER TABLE games DROP apworld_checked_at');
        $this->addSql('ALTER TABLE games DROP igdb_id');
    }
}
