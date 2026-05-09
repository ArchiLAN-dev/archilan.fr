<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260503130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop default_yaml_values from game_selection_games (replaced by defaultYaml from apworld upload).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_selection_games DROP COLUMN default_yaml_values');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_selection_games ADD COLUMN default_yaml_values JSON NOT NULL DEFAULT '[]'");
    }
}
