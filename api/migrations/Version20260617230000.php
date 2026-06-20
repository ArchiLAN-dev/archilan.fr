<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community: profile customization fields + audience (epic 30, story 30.3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile ADD COLUMN bio TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE community_profile ADD COLUMN tagline VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE community_profile ADD COLUMN pronouns VARCHAR(40) DEFAULT NULL');
        $this->addSql("ALTER TABLE community_profile ADD COLUMN banner_preset VARCHAR(32) NOT NULL DEFAULT 'default'");
        $this->addSql("ALTER TABLE community_profile ADD COLUMN social_links JSON NOT NULL DEFAULT '[]'");
        $this->addSql("ALTER TABLE community_profile ADD COLUMN favorite_game_ids JSON NOT NULL DEFAULT '[]'");
        $this->addSql("ALTER TABLE community_profile ADD COLUMN audience VARCHAR(16) NOT NULL DEFAULT 'members'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_profile DROP COLUMN bio');
        $this->addSql('ALTER TABLE community_profile DROP COLUMN tagline');
        $this->addSql('ALTER TABLE community_profile DROP COLUMN pronouns');
        $this->addSql('ALTER TABLE community_profile DROP COLUMN banner_preset');
        $this->addSql('ALTER TABLE community_profile DROP COLUMN social_links');
        $this->addSql('ALTER TABLE community_profile DROP COLUMN favorite_game_ids');
        $this->addSql('ALTER TABLE community_profile DROP COLUMN audience');
    }
}
