<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add apworld_minio_key to games table for MinIO object storage tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE games ADD apworld_minio_key VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE games DROP COLUMN apworld_minio_key');
    }
}
