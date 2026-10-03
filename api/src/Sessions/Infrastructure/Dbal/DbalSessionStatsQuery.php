<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Dbal;

use App\Sessions\Application\Query\SessionStatsQueryInterface;
use App\Sessions\Domain\Entity\Session;
use App\Shared\Application\Support\StatsPeriod;
use App\Shared\Infrastructure\Dbal\DbalSlotCheckSource;
use App\Shared\Infrastructure\Dbal\DbalStatsReader;
use Doctrine\DBAL\Connection;

/**
 * Reads the Parties section of the admin statistics page (story 42.2). A personal run's sessions carry the run
 * id in `event_id` (LaunchPersonalRunJobHandler), which is how a run launch is told from an event session.
 * Weekly attempts have no session row and are read from `weekly_entries`, like the other sections read the
 * tables they sum up in plain SQL.
 */
final readonly class DbalSessionStatsQuery implements SessionStatsQueryInterface
{
    private const int TOP_GAMES = 10;

    public function __construct(
        private Connection $connection,
        private DbalStatsReader $reader,
    ) {
    }

    public function stats(StatsPeriod $period): array
    {
        return [
            'runningSessions' => $this->reader->count('SELECT COUNT(*) FROM session WHERE status = :running', ['running' => Session::STATUS_RUNNING]),
            'activeRuns' => $this->reader->count("SELECT COUNT(*) FROM run WHERE status = 'active'"),
            'runsCreated' => $this->reader->trend($period, 'FROM run WHERE 1 = 1', 'created_at'),
            'runsLaunched' => $this->reader->trend(
                $period,
                'FROM session s JOIN run r ON r.id = s.event_id WHERE s.started_at IS NOT NULL',
                's.started_at',
                distinct: 'r.id',
            ),
            'eventSessionsLaunched' => $this->reader->trend(
                $period,
                'FROM session s WHERE s.started_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM run r WHERE r.id = s.event_id)',
                's.started_at',
            ),
            'weeklyLaunched' => $this->reader->trend($period, 'FROM weekly_entries WHERE 1 = 1', 'launched_at'),
            'weeklyCompleted' => $this->reader->trend($period, 'FROM weekly_entries WHERE 1 = 1', 'goal_reached_at'),
            'goalsReached' => $this->reader->trend($period, 'FROM session_slot WHERE 1 = 1', 'goal_reached_at'),
            'topGames' => $this->topGames($period),
        ];
    }

    /**
     * @return list<array{gameId: string, name: string, players: int, checks: int}>
     */
    private function topGames(StatsPeriod $period): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT g.id, g.name, COUNT(DISTINCT c.player) AS players, COUNT(DISTINCT c.check_id) AS checks
               FROM (SELECT slot.game_id, '.DbalSlotCheckSource::player().' AS player, f.id AS check_id
                       '.DbalSlotCheckSource::from().'
                        AND f.occurred_at >= :start AND f.occurred_at < :end) c
               JOIN game g ON g.id = c.game_id
              GROUP BY g.id, g.name
              ORDER BY players DESC, checks DESC, g.name
              LIMIT '.self::TOP_GAMES,
            DbalSlotCheckSource::params() + ['start' => $period->start->format(\DATE_ATOM), 'end' => $period->end->format(\DATE_ATOM)],
            DbalSlotCheckSource::types(),
        );

        $games = [];
        foreach ($rows as $row) {
            $games[] = [
                'gameId' => is_scalar($row['id']) ? (string) $row['id'] : '',
                'name' => is_scalar($row['name']) ? (string) $row['name'] : '',
                'players' => is_numeric($row['players']) ? (int) $row['players'] : 0,
                'checks' => is_numeric($row['checks']) ? (int) $row['checks'] : 0,
            ];
        }

        return $games;
    }
}
