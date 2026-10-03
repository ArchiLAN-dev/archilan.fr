<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 41.10: the video frames managed from the admin (and the overrides of the built-in ones).
 */
final class Version20261003200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 41.10: avatar_frame';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE avatar_frame (frame_key VARCHAR(32) NOT NULL, label VARCHAR(60) NOT NULL, access VARCHAR(10) NOT NULL, webm_key VARCHAR(255) DEFAULT NULL, mp4_key VARCHAR(255) DEFAULT NULL, poster_key VARCHAR(255) DEFAULT NULL, still_key VARCHAR(255) DEFAULT NULL, position INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, retired_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (frame_key))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE avatar_frame');
    }
}
