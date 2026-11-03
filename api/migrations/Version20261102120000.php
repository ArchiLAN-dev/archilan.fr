<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 49.1: an event's link to Minimapperz (slug, organiser API key, what a game is worth in pelles).
 */
final class Version20261102120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 49.1: minimapperz_setting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE minimapperz_setting (event_id VARCHAR(32) NOT NULL, enabled BOOLEAN NOT NULL, slug VARCHAR(100) DEFAULT NULL, api_key VARCHAR(200) DEFAULT NULL, pelles_per_game INT NOT NULL, pelles_per_win INT NOT NULL, cap_per_player INT NOT NULL, PRIMARY KEY(event_id))');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE minimapperz_setting');
    }
}
