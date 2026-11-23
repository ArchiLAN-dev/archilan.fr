<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 56.5: the option presets (short, medium, long) a player applies to a game's YAML.
 */
final class Version20261123100002 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Schema: game.option_presets';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD option_presets JSON DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP option_presets');
    }
}
