<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 38.30: a meta-game's test generations add a partner player.
 */
final class Version20261031120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 38.30: game.test_with_partner';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD test_with_partner BOOLEAN DEFAULT false NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP test_with_partner');
    }
}
