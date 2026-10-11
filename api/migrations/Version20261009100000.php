<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 43.2: the friend suggestions a member waved away.
 */
final class Version20261009100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 43.2: community_friend_suggestion_dismissal';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_friend_suggestion_dismissal (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, dismissed_user_id VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_community_friend_suggestion_dismissal ON community_friend_suggestion_dismissal (user_id, dismissed_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE community_friend_suggestion_dismissal');
    }
}
