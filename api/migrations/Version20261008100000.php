<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 30.53: an achievement given by an admin rather than earned by its rule.
 */
final class Version20261008100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 30.53: community_achievement_grant.by_team';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_achievement_grant ADD by_team BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_achievement_grant DROP by_team');
    }
}
