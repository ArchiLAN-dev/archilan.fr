<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\CommunityStatsQueryInterface;
use App\Community\Domain\Entity\Friendship;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Shared\Application\Support\StatsPeriod;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reads the Community section of the admin statistics page (story 42.1). Like the admin dashboard counters,
 * it reads the tables of the contexts it sums up (accounts, memberships, session feed) in plain SQL: a
 * statistics read is not a reason to give those contexts a dependency on Community.
 */
final readonly class DbalCommunityStatsQuery implements CommunityStatsQueryInterface
{
    /** A check is a feed line sent by a slot: an item found for someone, or the slot's own goal. */
    private const array CHECK_TYPES = [SessionFeedEvent::TYPE_ITEM_RECEIVED, SessionFeedEvent::TYPE_GOAL];

    public function __construct(private Connection $connection)
    {
    }

    public function stats(StatsPeriod $period, \DateTimeImmutable $now): array
    {
        $user = $this->connection->quoteSingleIdentifier('user');

        return [
            'accounts' => $this->count("SELECT COUNT(*) FROM {$user} WHERE deleted_at IS NULL", []),
            'members' => $this->count(
                "SELECT COUNT(DISTINCT user_id) FROM memberships WHERE status = 'active' AND expires_at >= :now",
                ['now' => $now->format(\DATE_ATOM)],
            ),
            'accountsCreated' => $this->trend($period, $user, 'created_at', '1 = 1', []),
            'membershipsStarted' => $this->trend($period, 'memberships', 'started_at', '1 = 1', []),
            'activePlayers' => $this->activePlayers($period),
            'friendshipsAccepted' => $this->trend($period, 'community_friendship', 'responded_at', 'status = :accepted', ['accepted' => Friendship::ACCEPTED]),
            'achievementsUnlocked' => $this->trend($period, 'community_achievement_grant', 'unlocked_at', '1 = 1', []),
        ];
    }

    /**
     * Rows of one table counted by bucket of their date column, plus the totals of the period and of the one
     * before.
     *
     * @param array<string, string> $params
     *
     * @return array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int}
     */
    private function trend(StatsPeriod $period, string $table, string $column, string $filter, array $params): array
    {
        $bounds = $this->bounds($period);
        $rows = $this->connection->fetchAllAssociative(
            "SELECT to_char(date_trunc('{$period->granularity}', {$column} AT TIME ZONE 'UTC'), 'YYYY-MM-DD') AS bucket, COUNT(*) AS n
               FROM {$table}
              WHERE {$filter} AND {$column} >= :start AND {$column} < :end
              GROUP BY 1",
            $params + $bounds,
        );
        $previous = $this->count(
            "SELECT COUNT(*) FROM {$table} WHERE {$filter} AND {$column} >= :previousStart AND {$column} < :start",
            $params + ['previousStart' => $bounds['previousStart'], 'start' => $bounds['start']],
        );

        return $this->assemble($period, $rows, $previous);
    }

    /**
     * Distinct accounts behind the slots that made a check: the feed names the sending slot, the slot leads to
     * its players (owner and co-players, through the same source as points and achievements).
     *
     * @return array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int}
     */
    private function activePlayers(StatsPeriod $period): array
    {
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $playerColumn = 'sp.'.DbalSlotPlayerSource::USER_COLUMN;
        $from = "FROM session_feed_event f
                 JOIN session_slot slot ON slot.session_id = f.session_id AND slot.slot_name = f.sender_name
                 JOIN {$players} sp ON sp.".DbalSlotPlayerSource::SLOT_COLUMN.' = slot.id
                WHERE f.type IN (:types)';
        $types = ['types' => ArrayParameterType::STRING];
        $bounds = $this->bounds($period);

        $rows = $this->connection->fetchAllAssociative(
            "SELECT to_char(date_trunc('{$period->granularity}', f.occurred_at AT TIME ZONE 'UTC'), 'YYYY-MM-DD') AS bucket,
                    COUNT(DISTINCT {$playerColumn}) AS n
               {$from} AND f.occurred_at >= :start AND f.occurred_at < :end
              GROUP BY 1",
            ['types' => self::CHECK_TYPES] + $bounds,
            $types,
        );
        $distinct = fn (string $start, string $end): int => $this->count(
            "SELECT COUNT(DISTINCT {$playerColumn}) {$from} AND f.occurred_at >= :from AND f.occurred_at < :to",
            ['types' => self::CHECK_TYPES, 'from' => $start, 'to' => $end],
            $types,
        );

        $result = $this->assemble($period, $rows, $distinct($bounds['previousStart'], $bounds['start']));
        $result['total'] = $distinct($bounds['start'], $bounds['end']);

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int}
     */
    private function assemble(StatsPeriod $period, array $rows, int $previous): array
    {
        $values = [];
        foreach ($rows as $row) {
            $bucket = $row['bucket'] ?? null;
            if (is_string($bucket)) {
                $values[$bucket] = $this->int($row['n'] ?? null);
            }
        }
        $series = $period->series($values);

        return ['series' => $series, 'total' => array_sum(array_column($series, 'value')), 'previous' => $previous];
    }

    /**
     * @return array{start: string, end: string, previousStart: string}
     */
    private function bounds(StatsPeriod $period): array
    {
        return [
            'start' => $period->start->format(\DATE_ATOM),
            'end' => $period->end->format(\DATE_ATOM),
            'previousStart' => $period->previousStart->format(\DATE_ATOM),
        ];
    }

    /**
     * @param array<string, mixed>                      $params
     * @param array<string, ArrayParameterType::STRING> $types
     */
    private function count(string $sql, array $params, array $types = []): int
    {
        return $this->int($this->connection->fetchOne($sql, $params, $types));
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
