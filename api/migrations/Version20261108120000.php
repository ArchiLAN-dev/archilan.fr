<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 30.57: the admin counters of checks and items that achievements read as `counter:{id}`.
 */
final class Version20261108120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Story 30.57: community_achievement_counter';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_achievement_counter (id VARCHAR(32) NOT NULL, label VARCHAR(80) NOT NULL, game_id VARCHAR(32) NOT NULL, kind VARCHAR(16) NOT NULL, targets JSON NOT NULL, mode VARCHAR(16) NOT NULL, with_others_only BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE community_achievement_counter');
    }
}
