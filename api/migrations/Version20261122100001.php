<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 56.2: the admin's estimate of a game's pace, used while our finished games do not measure it.
 */
final class Version20261122100001 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Schema: game.estimated_checks_per_hour';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD estimated_checks_per_hour INT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP estimated_checks_per_hour');
    }
}
