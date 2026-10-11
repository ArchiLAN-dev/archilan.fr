<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 43.3: a member's personal friend link, shown as a QR code.
 */
final class Version20261009120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 43.3: community_friend_link';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_friend_link (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, code VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, regenerated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_community_friend_link_user ON community_friend_link (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_community_friend_link_code ON community_friend_link (code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE community_friend_link');
    }
}
