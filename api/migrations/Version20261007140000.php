<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.28: cosmetics won through achievements and quests - where an owned cosmetic came from, and what an
 * achievement or a quest unlocks.
 */
final class Version20261007140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.28: owned_cosmetic.source, source_label; cosmetic rewards on achievements and quests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE owned_cosmetic ADD source VARCHAR(12) DEFAULT 'shop' NOT NULL");
        $this->addSql('ALTER TABLE owned_cosmetic ADD source_label VARCHAR(191) DEFAULT NULL');
        $this->addSql('ALTER TABLE community_achievement_definition ADD cosmetic_type VARCHAR(12) DEFAULT NULL');
        $this->addSql('ALTER TABLE community_achievement_definition ADD cosmetic_key VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE quest_definition ADD cosmetic_type VARCHAR(12) DEFAULT NULL');
        $this->addSql('ALTER TABLE quest_definition ADD cosmetic_key VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest_definition DROP cosmetic_key');
        $this->addSql('ALTER TABLE quest_definition DROP cosmetic_type');
        $this->addSql('ALTER TABLE community_achievement_definition DROP cosmetic_key');
        $this->addSql('ALTER TABLE community_achievement_definition DROP cosmetic_type');
        $this->addSql('ALTER TABLE owned_cosmetic DROP source_label');
        $this->addSql('ALTER TABLE owned_cosmetic DROP source');
    }
}
