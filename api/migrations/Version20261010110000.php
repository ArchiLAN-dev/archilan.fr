<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 43.11b: a member's choice per notification type (bell and push, bell only, nothing). No row = the default.
 */
final class Version20261010110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 43.11b: community_notification_preference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_notification_preference (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, type VARCHAR(32) NOT NULL, channel VARCHAR(16) NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_community_notification_preference ON community_notification_preference (user_id, type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE community_notification_preference');
    }
}
