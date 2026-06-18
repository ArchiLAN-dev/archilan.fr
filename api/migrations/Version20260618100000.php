<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: community_achievement_grant - persisted achievement unlocks (epic 30, story 30.4)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_achievement_grant (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, achievement_key VARCHAR(64) NOT NULL, unlocked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_community_achievement_grant ON community_achievement_grant (user_id, achievement_key)');
        $this->addSql('CREATE INDEX idx_community_achievement_grant_user ON community_achievement_grant (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE community_achievement_grant');
    }
}
