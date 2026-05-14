<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extract catalog sync fields from games to game_catalog_sync; add ignored_catalog_entries.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE game_catalog_sync (
                game_id VARCHAR(32) NOT NULL,
                catalog_sheet_name VARCHAR(160) DEFAULT NULL,
                apworld_source_url VARCHAR(500) DEFAULT NULL,
                apworld_deployed_version VARCHAR(50) DEFAULT NULL,
                apworld_latest_version VARCHAR(50) DEFAULT NULL,
                apworld_checked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                apworld_release_url VARCHAR(500) DEFAULT NULL,
                igdb_id INT DEFAULT NULL,
                PRIMARY KEY(game_id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE game_catalog_sync
                ADD CONSTRAINT FK_game_catalog_sync_game_id
                FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO game_catalog_sync (
                game_id,
                catalog_sheet_name,
                apworld_source_url,
                apworld_deployed_version,
                apworld_latest_version,
                apworld_checked_at,
                apworld_release_url,
                igdb_id
            )
            SELECT
                id,
                catalog_sheet_name,
                apworld_source_url,
                apworld_deployed_version,
                apworld_latest_version,
                apworld_checked_at,
                apworld_release_url,
                igdb_id
            FROM games
            WHERE catalog_sheet_name IS NOT NULL
               OR apworld_source_url IS NOT NULL
               OR apworld_deployed_version IS NOT NULL
               OR apworld_latest_version IS NOT NULL
               OR apworld_checked_at IS NOT NULL
               OR apworld_release_url IS NOT NULL
               OR igdb_id IS NOT NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE ignored_catalog_entries (
                name VARCHAR(160) NOT NULL,
                ignored_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY(name)
            )
        SQL);

        $this->addSql('ALTER TABLE games DROP COLUMN catalog_sheet_name');
        $this->addSql('ALTER TABLE games DROP COLUMN apworld_source_url');
        $this->addSql('ALTER TABLE games DROP COLUMN apworld_deployed_version');
        $this->addSql('ALTER TABLE games DROP COLUMN apworld_latest_version');
        $this->addSql('ALTER TABLE games DROP COLUMN apworld_checked_at');
        $this->addSql('ALTER TABLE games DROP COLUMN apworld_release_url');
        $this->addSql('ALTER TABLE games DROP COLUMN igdb_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE games ADD catalog_sheet_name VARCHAR(160) DEFAULT NULL');
        $this->addSql('ALTER TABLE games ADD apworld_source_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE games ADD apworld_deployed_version VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE games ADD apworld_latest_version VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE games ADD apworld_checked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE games ADD apworld_release_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE games ADD igdb_id INT DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE games g
            SET
                catalog_sheet_name    = s.catalog_sheet_name,
                apworld_source_url    = s.apworld_source_url,
                apworld_deployed_version = s.apworld_deployed_version,
                apworld_latest_version = s.apworld_latest_version,
                apworld_checked_at    = s.apworld_checked_at,
                apworld_release_url   = s.apworld_release_url,
                igdb_id               = s.igdb_id
            FROM game_catalog_sync s
            WHERE g.id = s.game_id
        SQL);

        $this->addSql('DROP TABLE ignored_catalog_entries');
        $this->addSql('DROP TABLE game_catalog_sync');
    }
}
