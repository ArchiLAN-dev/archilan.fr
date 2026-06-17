<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: community_profile - 1-1 companion to a User, lazily created (epic 30, story 30.1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_profile (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_community_profile_user ON community_profile (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE community_profile');
    }
}
