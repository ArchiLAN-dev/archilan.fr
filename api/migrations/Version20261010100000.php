<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 43.11a: the friends a member starred. One way: the friend never knows.
 */
final class Version20261010100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 43.11a: community_friend_favorite';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_friend_favorite (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, favorite_user_id VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_community_friend_favorite ON community_friend_favorite (user_id, favorite_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE community_friend_favorite');
    }
}
