<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: in-app notification center (epic 30, story 30.12)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_notification (id VARCHAR(32) NOT NULL, recipient_id VARCHAR(32) NOT NULL, type VARCHAR(32) NOT NULL, payload JSON NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, read_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_community_notification_recipient ON community_notification (recipient_id, read_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE community_notification');
    }
}
