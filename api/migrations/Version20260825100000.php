<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 16.17: a slot can be played by several people. The row points at the game slot id
 * (SessionSlot.slot_id), not at a session slot row, so a co-player can be named before the run is
 * launched and survives a relaunch.
 */
final class Version20260825100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create slot_co_player (story 16.17)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE slot_co_player (
                id VARCHAR(36) NOT NULL,
                slot_id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                added_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_slot_co_player ON slot_co_player (slot_id, user_id)');
        $this->addSql('CREATE INDEX idx_slot_co_player_user ON slot_co_player (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE slot_co_player');
    }
}
