<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260503110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add apworld storage fields to game_selection_games.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_selection_games ADD apworld_storage_key VARCHAR(500) DEFAULT NULL, ADD apworld_hash VARCHAR(64) DEFAULT NULL, ADD apworld_uploaded_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD default_yaml TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_selection_games DROP apworld_storage_key, DROP apworld_hash, DROP apworld_uploaded_at, DROP default_yaml');
    }
}
