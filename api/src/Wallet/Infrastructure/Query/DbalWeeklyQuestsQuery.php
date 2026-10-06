<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Query;

use App\Shared\Infrastructure\Dbal\DbalSlotCheckSource;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use App\Wallet\Application\Query\WeeklyQuestsQueryInterface;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\ValueObject\QuestObjective;
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

    public function counts(QuestWeek $week, array $objectives, ?string $userId = null): array
    {
        $counts = [];
        foreach ($objectives as $objective) {
            // Story 41.18: an objective on a game or an event counts only that game's or event's sessions.
            $scope = $this->scope($objective);
            $rows = match ($objective->metric) {
                QuestMetric::Goals => $this->goals($week, $scope),
                QuestMetric::Checks => $this->checks($week, $scope),
                QuestMetric::Weeklies => $this->weeklies($week),
                QuestMetric::NewPartners => $this->newPartners($week),
                QuestMetric::Sessions => $this->perCheck($week, 'COUNT(DISTINCT f.session_id)', $scope),
                QuestMetric::DistinctGames => $this->perCheck($week, 'COUNT(DISTINCT slot.game_id)', null),
            };
            $counts[$objective->key()] = $this->byMember($rows, $userId);
        }

        return $counts;
    }

    public function scopeOptions(): array
    {
        $games = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT DISTINCT g.id, g.name FROM session_slot slot JOIN game g ON g.id = slot.game_id ORDER BY g.name',
        ) as $row) {
            if (is_string($row['id'] ?? null) && is_string($row['name'] ?? null)) {
                $games[] = ['id' => $row['id'], 'name' => $row['name']];
            }
        }

        $events = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT id, title FROM event WHERE status <> 'draft' ORDER BY starts_at DESC",
        ) as $row) {
            if (is_string($row['id'] ?? null) && is_string($row['title'] ?? null)) {
                $events[] = ['id' => $row['id'], 'title' => $row['title']];
            }
        }

        return ['games' => $games, 'events' => $events];
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

    public function activeMembers(\DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $checks = DbalSlotCheckSource::from();
        $player = DbalSlotCheckSource::player();

        return $this->ids($this->connection->fetchFirstColumn(
            "SELECT DISTINCT {$player} {$checks} AND f.occurred_at >= :since AND f.occurred_at < :until
             UNION
             SELECT DISTINCT user_id FROM weekly_entries
              WHERE launched_at >= :since AND launched_at < :until
                AND (COALESCE(checks_total, 0) > 0 OR goal_reached_at IS NOT NULL)",
            DbalSlotCheckSource::params() + ['since' => $since->format(\DATE_ATOM), 'until' => $until->format(\DATE_ATOM)],
            DbalSlotCheckSource::types(),
        ));
    }

    public function earnedBy(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT unique_key, amount FROM pelle_movement WHERE user_id = :userId AND reason = :reason AND unique_key IS NOT NULL',
            ['userId' => $userId, 'reason' => PelleReason::QuestReward->value],
        );

        $weeks = [];
        foreach ($rows as $row) {
            $key = $row['unique_key'] ?? null;
            $amount = filter_var($row['amount'] ?? null, \FILTER_VALIDATE_INT);
            if (!is_string($key) || false === $amount) {
                continue;
            }
            // quest:{week}:{quest}:{member} or quest-chest:{week}:{member}
            $parts = explode(':', $key);
            $week = $parts[1] ?? '';
            $weeks[$week] ??= ['quests' => [], 'pelles' => 0, 'chest' => false];
            $weeks[$week]['pelles'] += $amount;
            if ('quest' === $parts[0] && 4 === \count($parts)) {
                $weeks[$week]['quests'][] = $parts[2];
            } elseif ('quest-chest' === $parts[0]) {
                $weeks[$week]['chest'] = true;
            }
        }

        return $weeks;
    }

    public function chestPaid(string $userId, QuestWeek $week): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM pelle_movement WHERE user_id = :userId AND reason = :reason AND unique_key = :key',
            ['userId' => $userId, 'reason' => PelleReason::QuestReward->value, 'key' => sprintf('quest-chest:%s:%s', $week->key, $userId)],
        );
    }

    public function payments(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT unique_key, amount FROM pelle_movement WHERE reason = :reason AND unique_key IS NOT NULL',
            ['reason' => PelleReason::QuestReward->value],
        );

        $weeks = [];
        foreach ($rows as $row) {
            $key = $row['unique_key'] ?? null;
            $amount = filter_var($row['amount'] ?? null, \FILTER_VALIDATE_INT);
            if (!is_string($key) || false === $amount) {
                continue;
            }
            // quest:{week}:{quest}:{member} or quest-chest:{week}:{member}
            $parts = explode(':', $key);
            $week = $parts[1] ?? '';
            $weeks[$week] ??= ['quests' => [], 'chests' => 0, 'chestPelles' => 0];
            if ('quest' === $parts[0] && 4 === \count($parts)) {
                $quest = $weeks[$week]['quests'][$parts[2]] ?? ['members' => 0, 'pelles' => 0];
                $weeks[$week]['quests'][$parts[2]] = ['members' => $quest['members'] + 1, 'pelles' => $quest['pelles'] + $amount];
            } elseif ('quest-chest' === $parts[0] && 3 === \count($parts)) {
                ++$weeks[$week]['chests'];
                $weeks[$week]['chestPelles'] += $amount;
            }
        }

        return $weeks;
    }

    /**
     * @param array{sql: string, id: string}|null $scope
     *
     * @return list<array<string, mixed>>
     */
    private function goals(QuestWeek $week, ?array $scope): array
    {
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $playerColumn = 'sp.'.DbalSlotPlayerSource::USER_COLUMN;
        // A weekly attempt belongs to no game or event the admin can aim at: a scoped objective counts sessions only.
        $weeklies = null === $scope ? 'UNION ALL
                 SELECT id AS ref, user_id AS uid FROM weekly_entries
                  WHERE goal_reached_at >= :weekStart AND goal_reached_at < :weekEnd' : '';

        return $this->connection->fetchAllAssociative(
            "SELECT uid, COUNT(*) AS n FROM (
                 SELECT DISTINCT slot.id AS ref, {$playerColumn} AS uid
                   FROM session_slot slot
                   JOIN {$players} sp ON sp.".DbalSlotPlayerSource::SLOT_COLUMN.' = slot.id
                  WHERE slot.goal_reached_at >= :weekStart AND slot.goal_reached_at < :weekEnd '.($scope['sql'] ?? '').'
                 '.$weeklies.'
             ) reached GROUP BY uid',
            $this->bounds($week) + (null === $scope ? [] : ['scopeId' => $scope['id']]),
        );
    }

    /**
     * @param array{sql: string, id: string}|null $scope
     *
     * @return list<array<string, mixed>>
     */
    private function checks(QuestWeek $week, ?array $scope): array
    {
        $checks = DbalSlotCheckSource::from();
        $player = DbalSlotCheckSource::player();
        $weeklies = null === $scope ? 'UNION ALL
                 SELECT user_id AS uid, SUM(COALESCE(checks_total, 0)) AS n FROM weekly_entries
                  WHERE launched_at >= :weekStart AND launched_at < :weekEnd GROUP BY user_id' : '';

        return $this->connection->fetchAllAssociative(
            "SELECT uid, SUM(n) AS n FROM (
                 SELECT {$player} AS uid, COUNT(*) AS n {$checks} AND f.occurred_at >= :weekStart AND f.occurred_at < :weekEnd ".($scope['sql'] ?? '')." GROUP BY {$player}
                 {$weeklies}
             ) made GROUP BY uid",
            DbalSlotCheckSource::params() + $this->bounds($week) + (null === $scope ? [] : ['scopeId' => $scope['id']]),
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

    /**
     * @param array{sql: string, id: string}|null $scope
     *
     * @return list<array<string, mixed>>
     */
    private function perCheck(QuestWeek $week, string $aggregate, ?array $scope): array
    {
        $checks = DbalSlotCheckSource::from();
        $player = DbalSlotCheckSource::player();

        return $this->connection->fetchAllAssociative(
            "SELECT {$player} AS uid, {$aggregate} AS n {$checks} AND f.occurred_at >= :weekStart AND f.occurred_at < :weekEnd ".($scope['sql'] ?? '')." GROUP BY {$player}",
            DbalSlotCheckSource::params() + $this->bounds($week) + (null === $scope ? [] : ['scopeId' => $scope['id']]),
            DbalSlotCheckSource::types(),
        );
    }

    /**
     * Story 41.18: the condition on the slot (`slot`) that narrows a count to the objective's game or event.
     *
     * @return array{sql: string, id: string}|null
     */
    private function scope(QuestObjective $objective): ?array
    {
        return match ($objective->scope) {
            QuestObjective::SCOPE_GAME => ['sql' => 'AND slot.game_id = :scopeId', 'id' => (string) $objective->scopeId],
            QuestObjective::SCOPE_EVENT => ['sql' => 'AND slot.session_id IN (SELECT ev.id FROM session ev WHERE ev.event_id = :scopeId)', 'id' => (string) $objective->scopeId],
            default => null,
        };
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

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private function ids(array $values): array
    {
        return array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && '' !== $value));
    }
}
