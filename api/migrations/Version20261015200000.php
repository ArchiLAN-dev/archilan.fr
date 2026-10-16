<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 44.3: the files a world reads from the player (a ROM), declared and proven by a failed generation.
 */
final class Version20261015200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 44.3: game.required_files';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD required_files JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP required_files');
    }
}
