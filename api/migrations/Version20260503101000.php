<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260503101000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add photo gallery URLs to events.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events_events ADD photo_gallery JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE events_events DROP photo_gallery');
    }
}
