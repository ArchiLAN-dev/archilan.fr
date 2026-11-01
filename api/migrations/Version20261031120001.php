<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.67: a week entry freezes what its quest asks and pays, and the game drawn for it.
 */
final class Version20261031120001 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 41.67: quest_week_entry.objectives, reward, drawn_game_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest_week_entry ADD objectives JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE quest_week_entry ADD reward INT DEFAULT NULL');
        $this->addSql('ALTER TABLE quest_week_entry ADD drawn_game_id VARCHAR(32) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest_week_entry DROP objectives');
        $this->addSql('ALTER TABLE quest_week_entry DROP reward');
        $this->addSql('ALTER TABLE quest_week_entry DROP drawn_game_id');
    }
}
