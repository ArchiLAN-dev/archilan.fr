<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: friendships + blocks (epic 30, story 30.7)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_friendship (id VARCHAR(32) NOT NULL, requester_id VARCHAR(32) NOT NULL, addressee_id VARCHAR(32) NOT NULL, pair_key VARCHAR(65) NOT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, responded_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_community_friendship_pair ON community_friendship (pair_key)');
        $this->addSql('CREATE INDEX idx_community_friendship_addressee ON community_friendship (addressee_id, status)');
        $this->addSql('CREATE INDEX idx_community_friendship_requester ON community_friendship (requester_id, status)');

        $this->addSql('CREATE TABLE community_block (id VARCHAR(32) NOT NULL, blocker_id VARCHAR(32) NOT NULL, blocked_id VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_community_block ON community_block (blocker_id, blocked_id)');
        $this->addSql('CREATE INDEX idx_community_block_blocked ON community_block (blocked_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE community_friendship');
        $this->addSql('DROP TABLE community_block');
    }
}
