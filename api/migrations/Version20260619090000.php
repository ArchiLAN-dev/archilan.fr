<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 31.1 - per-game install tutorial steps (ordered JSON) on the game table.
 */
final class Version20260619090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add install_steps JSON column to game (per-game install tutorial, story 31.1).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD COLUMN install_steps JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP COLUMN install_steps');
    }
}
