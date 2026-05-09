<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260502230826 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE content_posts (id VARCHAR(32) NOT NULL, slug VARCHAR(120) NOT NULL, title VARCHAR(200) NOT NULL, type VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, excerpt TEXT NOT NULL, body JSON NOT NULL, reading_time VARCHAR(20) NOT NULL, related_event_slug VARCHAR(120) DEFAULT NULL, vod_url VARCHAR(500) DEFAULT NULL, published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_639EEE28989D9B62 ON content_posts (slug)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE content_posts');
    }
}
