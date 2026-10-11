<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 43.13: a member's private groups of friends, and who is in each.
 */
final class Version20261010130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 43.13: community_friend_group, community_friend_group_member';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_friend_group (id VARCHAR(32) NOT NULL, owner_id VARCHAR(32) NOT NULL, name VARCHAR(60) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_community_friend_group_owner ON community_friend_group (owner_id)');
        $this->addSql('CREATE TABLE community_friend_group_member (id VARCHAR(32) NOT NULL, group_id VARCHAR(32) NOT NULL, owner_id VARCHAR(32) NOT NULL, member_id VARCHAR(32) NOT NULL, added_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_community_friend_group_member ON community_friend_group_member (group_id, member_id)');
        $this->addSql('CREATE INDEX idx_community_friend_group_member_owner ON community_friend_group_member (owner_id, member_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE community_friend_group_member');
        $this->addSql('DROP TABLE community_friend_group');
    }
}
