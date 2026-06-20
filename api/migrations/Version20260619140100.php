<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Community: append-only moderation action audit log (story 30.29) - one row per warn/suspend/ban/lift.
 */
final class Version20260619140100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: community_moderation_action audit log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_moderation_action (id VARCHAR(32) NOT NULL, actor_id VARCHAR(32) NOT NULL, target_user_id VARCHAR(32) NOT NULL, action VARCHAR(16) NOT NULL, reason VARCHAR(500) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, related_report_id VARCHAR(32) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_community_mod_action_target ON community_moderation_action (target_user_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE community_moderation_action');
    }
}
