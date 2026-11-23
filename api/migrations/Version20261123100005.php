<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 56.8: the duration each slot was expected to last, frozen at generation, to compare with the real one.
 */
final class Version20261123100005 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Schema: session_slot.estimated_hours';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session_slot ADD estimated_hours DOUBLE PRECISION DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session_slot DROP estimated_hours');
    }
}
