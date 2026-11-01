<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.68: a quest may have tiers, frozen with it in the weeks it is served.
 */
final class Version20261031120002 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 41.68: quest_definition.tiers, quest_week_entry.tiers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest_definition ADD tiers JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE quest_week_entry ADD tiers JSON DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest_definition DROP tiers');
        $this->addSql('ALTER TABLE quest_week_entry DROP tiers');
    }
}
