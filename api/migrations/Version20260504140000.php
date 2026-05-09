<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop randomizer_options and option_schema_version from games (story 12-1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_selection_games DROP COLUMN randomizer_options');
        $this->addSql('ALTER TABLE game_selection_games DROP COLUMN option_schema_version');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_selection_games ADD COLUMN randomizer_options JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE game_selection_games ADD COLUMN option_schema_version INTEGER NOT NULL DEFAULT 1');
    }
}
