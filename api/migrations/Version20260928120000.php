<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 39.5: the outcome of a sanction applied on the Discord server.
 */
final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 39.5: community_moderation_action.discord_server_status';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_moderation_action ADD discord_server_status VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_moderation_action DROP discord_server_status');
    }
}
