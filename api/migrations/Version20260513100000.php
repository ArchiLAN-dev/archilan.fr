<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cover_image_key to events table for MinIO media storage.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events ADD cover_image_key VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events DROP COLUMN cover_image_key');
    }
}
