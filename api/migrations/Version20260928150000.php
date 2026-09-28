<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 39.7: the Discord bans the site does not apply, told to the staff once.
 */
final class Version20260928150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 39.7: moderation_discord_ban_notice';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE moderation_discord_ban_notice (
            discord_user_id VARCHAR(32) NOT NULL,
            reason VARCHAR(16) NOT NULL,
            noticed_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (discord_user_id)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE moderation_discord_ban_notice');
    }
}
