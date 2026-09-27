<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 39.4: the bot's direct message on each sanction, and the member's answers read back from it.
 */
final class Version20260928090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 39.4: DM outcome of a sanction, DM channel and cursor of a case, Discord id of a message';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_moderation_action ADD discord_dm_status VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE moderation_case ADD dm_channel_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE moderation_case ADD dm_cursor VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE moderation_case_message ADD discord_message_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_moderation_case_message_discord ON moderation_case_message (discord_message_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_moderation_case_message_discord');
        $this->addSql('ALTER TABLE moderation_case_message DROP discord_message_id');
        $this->addSql('ALTER TABLE moderation_case DROP dm_cursor');
        $this->addSql('ALTER TABLE moderation_case DROP dm_channel_id');
        $this->addSql('ALTER TABLE community_moderation_action DROP discord_dm_status');
    }
}
