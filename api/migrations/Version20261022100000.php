<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 32.24: a run's times start at the server's first launch, which never moves. `started_at` was overwritten by
 * some relaunch paths, so the true first launch may be lost: the backfill takes the earliest instant known for the
 * session (last launch, first goal, first feed event, end). Nothing played can precede the launch, so it is the best
 * bound available and no time computed from it can be negative.
 */
final class Version20261022100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Story 32.24: session.first_launched_at, backfilled for launched sessions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session ADD first_launched_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE session s
               SET first_launched_at = LEAST(
                       s.started_at,
                       (SELECT MIN(sl.goal_reached_at) FROM session_slot sl WHERE sl.session_id = s.id),
                       (SELECT MIN(f.occurred_at) FROM session_feed_event f WHERE f.session_id = s.id),
                       s.finished_at)
             WHERE s.started_at IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session DROP first_launched_at');
    }
}
