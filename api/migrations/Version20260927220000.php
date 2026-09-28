<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 39.3: the outcome of the direct message carrying a staff reply.
 */
final class Version20260927220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 39.3: moderation_case_message.discord_dm_status';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE moderation_case_message ADD discord_dm_status VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE moderation_case_message DROP discord_dm_status');
    }
}
