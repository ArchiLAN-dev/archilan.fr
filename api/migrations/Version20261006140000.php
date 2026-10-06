<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.22: profile titles the admins write, and the title a profile wears.
 */
final class Version20261006140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.22: profile_title, community_profile.title_key';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE profile_title (title_key VARCHAR(32) NOT NULL, label VARCHAR(40) NOT NULL, access VARCHAR(10) NOT NULL, position INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, retired_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (title_key))');
        $this->addSql('ALTER TABLE community_profile ADD title_key VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile DROP title_key');
        $this->addSql('DROP TABLE profile_title');
    }
}
