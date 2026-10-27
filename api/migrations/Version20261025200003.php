<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 30.55: the facts of each member kept from the achievement recompute, for the stats page.
 */
final class Version20261025200003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 30.55: member_stats_snapshot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE member_stats_snapshot (user_id VARCHAR(36) NOT NULL, facts JSON NOT NULL, computed_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (user_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE member_stats_snapshot');
    }
}
