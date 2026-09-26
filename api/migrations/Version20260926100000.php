<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 38.5 review: when the nightly check last tried a game. `apworld_checked_at` holds the release
 * publication date, so resuming after a GitHub rate limit sorted on the wrong date.
 */
final class Version20260926100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 38.5 review: game_catalog_sync.apworld_last_checked_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_catalog_sync ADD apworld_last_checked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_catalog_sync DROP apworld_last_checked_at');
    }
}
