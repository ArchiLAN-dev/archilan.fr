<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 39.9: when the Discord bans present at activation were told to the staff.
 */
final class Version20260928180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 39.9: moderation_discord_ban_baseline';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE moderation_discord_ban_baseline (
            id SMALLINT NOT NULL,
            taken_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE moderation_discord_ban_baseline');
    }
}
