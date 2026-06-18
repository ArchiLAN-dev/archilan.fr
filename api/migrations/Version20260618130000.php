<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: activity feed entries (epic 30, story 30.8)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_activity_entry (id VARCHAR(32) NOT NULL, actor_id VARCHAR(32) NOT NULL, type VARCHAR(32) NOT NULL, subject_ref VARCHAR(191) NOT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, payload JSON NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_community_activity ON community_activity_entry (actor_id, type, subject_ref)');
        $this->addSql('CREATE INDEX idx_community_activity_actor_time ON community_activity_entry (actor_id, occurred_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE community_activity_entry');
    }
}
