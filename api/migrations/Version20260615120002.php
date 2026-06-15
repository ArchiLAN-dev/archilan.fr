<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615120002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'GameCatalogSync: add platforms (raw IGDB platforms) for catalog platform categories (story 28.6)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_catalog_sync ADD COLUMN platforms JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_catalog_sync DROP COLUMN platforms');
    }
}
