<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 56.4: the spoiler's progression kept with a balance report, for the party simulation.
 */
final class Version20261123100003 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Schema: balance_report.playthrough';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE balance_report ADD playthrough JSON DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE balance_report DROP playthrough');
    }
}
