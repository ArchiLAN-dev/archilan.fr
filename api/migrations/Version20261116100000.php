<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Story 54.1: PopTracker packs attached to a game, and their imported versions. */
final class Version20261116100000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Trackers: tracker_pack and tracker_pack_version';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tracker_pack (id VARCHAR(32) NOT NULL, game_id VARCHAR(32) NOT NULL, source_url TEXT NOT NULL, package_uid VARCHAR(120) NOT NULL, name VARCHAR(200) NOT NULL, author VARCHAR(200) DEFAULT NULL, game_name VARCHAR(200) DEFAULT NULL, license_spdx VARCHAR(64) DEFAULT NULL, versions_url TEXT DEFAULT NULL, current_version_id VARCHAR(32) NOT NULL, created_by VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_tracker_pack_game_uid ON tracker_pack (game_id, package_uid)');
        $this->addSql('CREATE TABLE tracker_pack_version (id VARCHAR(32) NOT NULL, pack_id VARCHAR(32) NOT NULL, package_version VARCHAR(64) DEFAULT NULL, git_tag VARCHAR(120) DEFAULT NULL, download_url TEXT NOT NULL, sha256 VARCHAR(64) NOT NULL, storage_key VARCHAR(255) NOT NULL, root_path VARCHAR(255) NOT NULL, size_bytes INT NOT NULL, variants JSON NOT NULL, min_poptracker_version VARCHAR(64) DEFAULT NULL, target_poptracker_version VARCHAR(64) DEFAULT NULL, imported_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_tracker_pack_version_pack ON tracker_pack_version (pack_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tracker_pack_version');
        $this->addSql('DROP TABLE tracker_pack');
    }
}
