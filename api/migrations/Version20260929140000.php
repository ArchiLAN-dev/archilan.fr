<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 40.2: the devices that accepted the site's browser push notifications.
 */
final class Version20260929140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 40.2: push_subscription';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE push_subscription (
            id VARCHAR(36) NOT NULL,
            user_id VARCHAR(36) NOT NULL,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(255) NOT NULL,
            content_encoding VARCHAR(16) NOT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_push_subscription_endpoint ON push_subscription (endpoint)');
        $this->addSql('CREATE INDEX idx_push_subscription_user ON push_subscription (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE push_subscription');
    }
}
