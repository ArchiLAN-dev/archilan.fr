<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop vod_url and related_event_slug from post table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE post DROP COLUMN vod_url');
        $this->addSql('ALTER TABLE post DROP COLUMN related_event_slug');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE post ADD COLUMN vod_url VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE post ADD COLUMN related_event_slug VARCHAR(120) DEFAULT NULL');
    }
}
