<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'GameCatalogSync: add steam_app_id (resolved from IGDB external_games) for Steam library coupling (story 28.1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_catalog_sync ADD COLUMN steam_app_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_catalog_sync DROP COLUMN steam_app_id');
    }
}
