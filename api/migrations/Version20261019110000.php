<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 9.58: the item names and item groups of a game's apworld, for the YAML editors' autocompletion.
 */
final class Version20261019110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 9.58: game.item_names, game.item_groups';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD item_names JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD item_groups JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP item_names');
        $this->addSql('ALTER TABLE game DROP item_groups');
    }
}
