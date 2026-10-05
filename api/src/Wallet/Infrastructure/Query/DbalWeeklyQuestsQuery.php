<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Query;

use App\Shared\Infrastructure\Dbal\DbalSlotCheckSource;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use App\Wallet\Application\Query\WeeklyQuestsQueryInterface;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\ValueObject\QuestWeek;
use Doctrine\DBAL\Connection;

/**
 * Counts what each member played over the week (stories 41.6, 41.15) from the session feed (checks, through the
 * slot's players), slot goals and weekly attempts. Like the statistics, it reads those tables in plain SQL without
 * making Wallet depend on Sessions or WeeklyRuns. Weekly attempts have no session feed: their checks count through
 * the attempt's own total.
 */
final readonly class DbalWeeklyQuestsQuery implements WeeklyQuestsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function counts(QuestWeek $week, array $metrics, ?string $userId = null): array
    {
        $counts = [];
        foreach ($metrics as $metric) {
            $rows = match ($metric) {
                QuestMetric::Goals => $this->goals($week),
                QuestMetric::Checks => $this->checks($week),
                QuestMetric::Weeklies => $this->weeklies($week),
                QuestMetric::NewPartners => $this->newPartners($week),
                QuestMetric::Sessions => $this->perCheck($week, 'COUNT(DISTINCT f.session_id)'),
                QuestMetric::DistinctGames => $this->perCheck($week, 'COUNT(DISTINCT slot.game_id)'),
            };
            $counts[$metric->value] = $this->byMember($rows, $userId);
        }

        return $counts;
    }

    public function rewardedQuests(string $userId, QuestWeek $week): array
    {
        $keys = $this->connection->fetchFirstColumn(
            'SELECT unique_key FROM pelle_movement WHERE user_id = :userId AND reason = :reason AND unique_key LIKE :prefix',
            ['userId' => $userId, 'reason' => PelleReason::QuestReward->value, 'prefix' => sprintf('quest:%s:%%', $week->key)],
        );

        $quests = [];
        foreach ($keys as $key) {
            // quest:{week}:{quest}:{user}
            $parts = explode(':', is_string($key) ? $key : '');
            if (4 === \count($parts)) {
                $quests[] = $parts[2];
            }
        }

        return $quests;
    }

    /** @return list<array<string, mixed>> */
    private function goals(QuestWeek $week): array
    {
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $playerColumn = 'sp.'.DbalSlotPlayerSource::USER_COLUMN;

        return $this->connection->fetchAllAssociative(
            "SELECT uid, COUNT(*) AS n FROM (
                 SELECT DISTINCT slot.id AS ref, {$playerColumn} AS uid
                   FROM session_slot slot
                   JOIN {$players} sp ON sp.".DbalSlotPlayerSource::SLOT_COLUMN.' = slot.id
                  WHERE slot.goal_reached_at >= :weekStart AND slot.goal_reached_at < :weekEnd
                 UNION ALL
                 SELECT id AS ref, user_id AS uid FROM weekly_entries
                  WHERE goal_reached_at >= :weekStart AND goal_reached_at < :weekEnd
             ) reached GROUP BY uid',
            $this->bounds($week),
        );
    }

    /** @return list<array<string, mixed>> */
    private function checks(QuestWeek $week): array
    {
        $checks = DbalSlotCheckSource::from();
        $player = DbalSlotCheckSource::player();

        return $this->connection->fetchAllAssociative(
            "SELECT uid, SUM(n) AS n FROM (
                 SELECT {$player} AS uid, COUNT(*) AS n {$checks} AND f.occurred_at >= :weekStart AND f.occurred_at < :weekEnd GROUP BY {$player}
                 UNION ALL
                 SELECT user_id AS uid, SUM(COALESCE(checks_total, 0)) AS n FROM weekly_entries
                  WHERE launched_at >= :weekStart AND launched_at < :weekEnd GROUP BY user_id
             ) made GROUP BY uid",
            DbalSlotCheckSource::params() + $this->bounds($week),
            DbalSlotCheckSource::types(),
        );
    }

    /** @return list<array<string, mixed>> */
    private function weeklies(QuestWeek $week): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT user_id AS uid, COUNT(*) AS n FROM weekly_entries
              WHERE launched_at >= :weekStart AND launched_at < :weekEnd
                AND (COALESCE(checks_total, 0) > 0 OR goal_reached_at IS NOT NULL)
              GROUP BY user_id',
            $this->bounds($week),
        );
    }

    /** @return list<array<string, mixed>> */
    private function newPartners(QuestWeek $week): array
    {
        $checks = DbalSlotCheckSource::from();
        $player = DbalSlotCheckSource::player();

        return $this->connection->fetchAllAssociative(
            "WITH this_week AS (
                 SELECT DISTINCT f.session_id, {$player} AS uid {$checks} AND f.occurred_at >= :weekStart AND f.occurred_at < :weekEnd
             ), before AS (
                 SELECT DISTINCT f.session_id, {$player} AS uid {$checks} AND f.occurred_at < :weekStart
             )
             SELECT a.uid, COUNT(DISTINCT b.uid) AS n
               FROM this_week a
               JOIN this_week b ON b.session_id = a.session_id AND b.uid <> a.uid
              WHERE NOT EXISTS (
                    SELECT 1 FROM before x JOIN before y ON y.session_id = x.session_id
                     WHERE x.uid = a.uid AND y.uid = b.uid
              )
              GROUP BY a.uid",
            DbalSlotCheckSource::params() + $this->bounds($week),
            DbalSlotCheckSource::types(),
        );
    }

    /** @return list<array<string, mixed>> */
    private function perCheck(QuestWeek $week, string $aggregate): array
    {
        $checks = DbalSlotCheckSource::from();
        $player = DbalSlotCheckSource::player();

        return $this->connection->fetchAllAssociative(
            "SELECT {$player} AS uid, {$aggregate} AS n {$checks} AND f.occurred_at >= :weekStart AND f.occurred_at < :weekEnd GROUP BY {$player}",
            DbalSlotCheckSource::params() + $this->bounds($week),
            DbalSlotCheckSource::types(),
        );
    }

    /** @return array{weekStart: string, weekEnd: string} */
    private function bounds(QuestWeek $week): array
    {
        return ['weekStart' => $week->start->format(\DATE_ATOM), 'weekEnd' => $week->end->format(\DATE_ATOM)];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int>
     */
    private function byMember(array $rows, ?string $userId): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $uid = $row['uid'] ?? null;
            $count = filter_var($row['n'] ?? null, \FILTER_VALIDATE_INT);
            if (!is_string($uid) || '' === $uid || false === $count || $count <= 0 || (null !== $userId && $uid !== $userId)) {
                continue;
            }
            $counts[$uid] = $count;
        }

        return $counts;
    }
}
