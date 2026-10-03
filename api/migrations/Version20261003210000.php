<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.11: the profile banners managed from the admin (and the overrides of the presets).
 */
final class Version20261003210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.11: profile_banner';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE profile_banner (banner_key VARCHAR(32) NOT NULL, label VARCHAR(60) NOT NULL, access VARCHAR(10) NOT NULL, image_key VARCHAR(255) DEFAULT NULL, webm_key VARCHAR(255) DEFAULT NULL, mp4_key VARCHAR(255) DEFAULT NULL, position INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, retired_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (banner_key))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE profile_banner');
    }
}
