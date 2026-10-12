<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 10.14: the announcements opened as a modal on the home page.
 */
final class Version20261012100000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 10.14: home_announcement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE home_announcement (id VARCHAR(32) NOT NULL, title VARCHAR(120) NOT NULL, body TEXT NOT NULL, cta_label VARCHAR(40) NOT NULL, cta_url VARCHAR(500) NOT NULL, starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, image_key VARCHAR(500) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_home_announcement_window ON home_announcement (starts_at, ends_at)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE home_announcement');
    }
}
