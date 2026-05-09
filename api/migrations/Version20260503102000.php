<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260503102000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cover image URL to content posts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_posts ADD cover_image_url VARCHAR(2048) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_posts DROP cover_image_url');
    }
}
