<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Dbal;

use App\Sessions\Application\Query\GamePaceQueryInterface;
use App\Sessions\Application\Query\PaceSample;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use Doctrine\DBAL\Connection;

/**
 * Story 56.2: the paces of the slots that reached their goal, from the session slots and the weekly entries.
 */
final readonly class DbalGamePaceQuery implements GamePaceQueryInterface
{
    /** Below a minute, a "goal" is a forfeit or a test, not a pace. */
    private const int MIN_SECONDS = 60;

    public function __construct(private Connection $connection)
    {
    }

    public function samples(): array
    {
        return [...$this->sessionSamples(), ...$this->weeklySamples()];
    }

    public function adminEstimates(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('g.id', 'g.estimated_checks_per_hour')
            ->from('game', 'g')
            ->where('g.estimated_checks_per_hour IS NOT NULL')
            ->executeQuery()
            ->fetchAllAssociative();

        $estimates = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $pace = $row['estimated_checks_per_hour'] ?? null;
            if (is_string($id) && is_numeric($pace) && (int) $pace > 0) {
                $estimates[$id] = (int) $pace;
            }
        }

        return $estimates;
    }

    /**
     * The time a slot took to reach its goal: the play time its spans measured up to the goal (story 32.26), so the
     * nights and pauses of an asynchronous run do not count as play. A slot with no span (played before the
     * heartbeat existed, or never seen connected) falls back to the time from the first launch to the goal.
     *
     * @return list<PaceSample>
     */
    private function sessionSamples(): array
    {
        $played = '(SELECT SUM(EXTRACT(EPOCH FROM (LEAST(p.last_seen_at, ss.goal_reached_at) - p.started_at)))
                      FROM session_slot_play_span p
                     WHERE p.session_id = ss.session_id
                       AND p.slot_name = ss.slot_name
                       AND p.started_at < ss.goal_reached_at)';
        $seconds = 'COALESCE('.$played.', EXTRACT(EPOCH FROM (ss.goal_reached_at - COALESCE(s.first_launched_at, s.started_at))))';
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('ss.id AS slot_id', 'ss.game_id', 'ss.checks_done', $seconds.' AS seconds', 'sp.'.DbalSlotPlayerSource::USER_COLUMN.' AS user_id')
            ->from('session_slot', 'ss')
            ->join('ss', 'session', 's', $qb->expr()->eq('s.id', 'ss.session_id'))
            ->leftJoin('ss', DbalSlotPlayerSource::expression('session_slot', 'registration'), 'sp', 'sp.'.DbalSlotPlayerSource::SLOT_COLUMN.' = ss.id')
            ->where('ss.goal_reached_at IS NOT NULL')
            ->andWhere('ss.checks_done > 0')
            ->andWhere('COALESCE(s.first_launched_at, s.started_at) IS NOT NULL')
            ->andWhere($seconds.' >= :minSeconds')
            ->setParameter('minSeconds', self::MIN_SECONDS)
            ->executeQuery()
            ->fetchAllAssociative();

        /** @var array<string, array{gameId: string, users: list<string>, pace: float}> $slots */
        $slots = [];
        foreach ($rows as $row) {
            $slotId = $row['slot_id'] ?? null;
            $gameId = $row['game_id'] ?? null;
            $checks = $row['checks_done'] ?? null;
            $elapsed = $row['seconds'] ?? null;
            if (!is_string($slotId) || !is_string($gameId) || !is_numeric($checks) || !is_numeric($elapsed) || (float) $elapsed <= 0) {
                continue;
            }
            $slots[$slotId] ??= ['gameId' => $gameId, 'users' => [], 'pace' => (float) $checks / ((float) $elapsed / 3600)];
            $userId = $row['user_id'] ?? null;
            if (is_string($userId) && '' !== $userId && !in_array($userId, $slots[$slotId]['users'], true)) {
                $slots[$slotId]['users'][] = $userId;
            }
        }

        return array_values(array_map(
            static fn (array $slot): PaceSample => new PaceSample($slot['gameId'], $slot['users'], $slot['pace']),
            $slots,
        ));
    }

    /** @return list<PaceSample> */
    private function weeklySamples(): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('t.game_id', 'e.user_id', 'e.checks_total', 'e.completion_time_seconds')
            ->from('weekly_entries', 'e')
            ->join('e', 'weekly_runs', 'r', $qb->expr()->eq('r.id', 'e.weekly_run_id'))
            ->join('r', 'weekly_templates', 't', $qb->expr()->eq('t.id', 'r.template_id'))
            ->where('e.goal_reached_at IS NOT NULL')
            ->andWhere('e.checks_total > 0')
            ->andWhere('e.completion_time_seconds >= :minSeconds')
            ->setParameter('minSeconds', self::MIN_SECONDS)
            ->executeQuery()
            ->fetchAllAssociative();

        $samples = [];
        foreach ($rows as $row) {
            $gameId = $row['game_id'] ?? null;
            $userId = $row['user_id'] ?? null;
            $checks = $row['checks_total'] ?? null;
            $elapsed = $row['completion_time_seconds'] ?? null;
            if (!is_string($gameId) || !is_string($userId) || !is_numeric($checks) || !is_numeric($elapsed) || (float) $elapsed <= 0) {
                continue;
            }
            $samples[] = new PaceSample($gameId, [$userId], (float) $checks / ((float) $elapsed / 3600));
        }

        return $samples;
    }
}
