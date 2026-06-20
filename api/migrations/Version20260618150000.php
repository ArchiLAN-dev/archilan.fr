<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: kudos reactions on runs/achievements (epic 30, story 30.11)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_kudos (id VARCHAR(32) NOT NULL, actor_id VARCHAR(32) NOT NULL, target_type VARCHAR(16) NOT NULL, target_id VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_community_kudos ON community_kudos (actor_id, target_type, target_id)');
        $this->addSql('CREATE INDEX idx_community_kudos_target ON community_kudos (target_type, target_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE community_kudos');
    }
}
