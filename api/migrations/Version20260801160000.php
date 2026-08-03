<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin-curated platform families for a game (story 9.47). IGDB describes the game - often
 * every platform it was ever released on - while the Archipelago world may support only one.
 * Kept on `game` rather than on `game_catalog_sync` so an IGDB resync never discards it;
 * NULL keeps the current behaviour (derive the families from the synced IGDB platforms).
 */
final class Version20260801160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add platform_families to game for the admin platform override (story 9.47)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD platform_families JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP platform_families');
    }
}
