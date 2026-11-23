<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 56.5: how long a world should last at an event (null = its length minus 20 %) and in a private run.
 */
final class Version20261123100001 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Schema: event.target_hours, run.target_hours';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD target_hours INT DEFAULT NULL');
        $this->addSql('ALTER TABLE run ADD target_hours INT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP target_hours');
        $this->addSql('ALTER TABLE run DROP target_hours');
    }
}
