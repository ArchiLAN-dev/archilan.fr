<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.69: a quest may be collective, paying from a member's share; its week keeps the community's total.
 */
final class Version20261031120003 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 41.69: quest_definition.collective and min_contribution, quest_week_entry.min_contribution, group_total and group_counted_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest_definition ADD collective BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE quest_definition ADD min_contribution INT DEFAULT NULL');
        $this->addSql('ALTER TABLE quest_week_entry ADD min_contribution INT DEFAULT NULL');
        $this->addSql('ALTER TABLE quest_week_entry ADD group_total INT DEFAULT NULL');
        $this->addSql('ALTER TABLE quest_week_entry ADD group_counted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest_definition DROP collective');
        $this->addSql('ALTER TABLE quest_definition DROP min_contribution');
        $this->addSql('ALTER TABLE quest_week_entry DROP min_contribution');
        $this->addSql('ALTER TABLE quest_week_entry DROP group_total');
        $this->addSql('ALTER TABLE quest_week_entry DROP group_counted_at');
    }
}
