<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260503120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cover_image_url to game_selection_games.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_selection_games ADD cover_image_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_selection_games DROP cover_image_url');
    }
}
