<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use Doctrine\DBAL\Connection;

final readonly class LeaderboardQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Goals and checks: ORDER BY, LIMIT, OFFSET, and total COUNT pushed to SQL.
     *
     * @return array{list<array{slug: string, displayName: string, value: int}>, int}
     */
    public function computeAggregatePage(string $axis, ?string $eventId, int $limit, int $offset): array
    {
        $selectValue = 'goals' === $axis ? 'COUNT(slot.id)' : 'COALESCE(SUM(slot.checks_done), 0)';
        $axisFilter = 'goals' === $axis
            ? 'AND slot.goal_reached_at IS NOT NULL'
            : 'AND NOT (slot.was_released AND slot.goal_reached_at IS NULL)';

        $params = [];
        $eventFilter = '';
        $prUnion = '';
        if (null !== $eventId) {
            $eventFilter = 'AND s.event_id = :eventId';
            $params['eventId'] = $eventId;
        } else {
            $prUnion = <<<SQL
                UNION ALL
                SELECT slot.registration_id AS user_id, {$selectValue} AS value
                FROM archipelago_session_slots slot
                JOIN archipelago_sessions s ON slot.session_id = s.id
                WHERE s.status = 'finished'
                  {$axisFilter}
                  AND EXISTS (SELECT 1 FROM personal_runs pr WHERE pr.session_id = s.id)
                GROUP BY slot.registration_id
            SQL;
        }

        $innerSql = <<<SQL
            SELECT reg.user_id AS user_id, {$selectValue} AS value
            FROM archipelago_session_slots slot
            JOIN event_registrations reg ON slot.registration_id = reg.id
            JOIN archipelago_sessions s ON slot.session_id = s.id
            WHERE s.status = 'finished'
              {$axisFilter}
              {$eventFilter}
            GROUP BY reg.user_id
            {$prUnion}
        SQL;

        $countRow = $this->connection->fetchOne(
            "SELECT COUNT(DISTINCT sub.user_id) FROM ({$innerSql}) sub",
            $params,
        );
        $total = is_numeric($countRow) ? (int) $countRow : 0;

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT sub.user_id, SUM(sub.value) AS total_value,
                       COALESCE(u.slug, '') AS slug,
                       u.display_name
                FROM ({$innerSql}) sub
                JOIN identity_users u ON u.id = sub.user_id
                GROUP BY sub.user_id, u.slug, u.display_name
                ORDER BY total_value DESC, LOWER(COALESCE(u.display_name, u.slug, '')) ASC
                LIMIT :limit OFFSET :offset
            SQL,
            array_merge($params, ['limit' => $limit, 'offset' => $offset]),
        );

        $entries = [];
        foreach ($rows as $row) {
            $slug = is_string($row['slug'] ?? null) ? $row['slug'] : '';
            $displayName = is_string($row['display_name'] ?? null) ? $row['display_name'] : '';
            $value = is_numeric($row['total_value'] ?? null) ? (int) $row['total_value'] : 0;
            $entries[] = ['slug' => $slug, 'displayName' => $displayName, 'value' => $value];
        }

        return [$entries, $total];
    }

    /**
     * Speed axis: SQL GROUP BY user+session reduces rows from N slots to one per user-session pair.
     * PHP then computes the minimum diff, sorts, and paginates the reduced result set.
     *
     * @return array{list<array{slug: string, displayName: string, value: int}>, int}
     */
    public function computeSpeedPage(?string $eventId, int $limit, int $offset): array
    {
        $params = [];
        $eventFilter = '';
        $prUnion = '';
        if (null !== $eventId) {
            $eventFilter = 'AND s.event_id = :eventId';
            $params['eventId'] = $eventId;
        } else {
            $prUnion = <<<SQL
                UNION ALL
                SELECT slot.registration_id AS user_id,
                       MIN(slot.goal_reached_at) AS earliest_goal_at,
                       s.started_at
                FROM archipelago_session_slots slot
                JOIN archipelago_sessions s ON slot.session_id = s.id
                WHERE s.status = 'finished'
                  AND slot.goal_reached_at IS NOT NULL
                  AND EXISTS (SELECT 1 FROM personal_runs pr WHERE pr.session_id = s.id)
                GROUP BY slot.registration_id, s.id, s.started_at
            SQL;
        }

        $allRows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT reg.user_id AS user_id,
                       MIN(slot.goal_reached_at) AS earliest_goal_at,
                       s.started_at
                FROM archipelago_session_slots slot
                JOIN event_registrations reg ON slot.registration_id = reg.id
                JOIN archipelago_sessions s ON slot.session_id = s.id
                WHERE s.status = 'finished'
                  AND slot.goal_reached_at IS NOT NULL
                  {$eventFilter}
                GROUP BY reg.user_id, s.id, s.started_at
                {$prUnion}
            SQL,
            $params,
        );

        /** @var array<string, int> $scores */
        $scores = [];
        foreach ($allRows as $row) {
            $userId = is_string($row['user_id'] ?? null) ? $row['user_id'] : '';
            if ('' === $userId) {
                continue;
            }
            $goalAt = is_string($row['earliest_goal_at'] ?? null) ? $row['earliest_goal_at'] : null;
            $startedAt = is_string($row['started_at'] ?? null) ? $row['started_at'] : null;
            if (null === $goalAt || null === $startedAt) {
                continue;
            }
            try {
                $seconds = (new \DateTimeImmutable($goalAt))->getTimestamp()
                    - (new \DateTimeImmutable($startedAt))->getTimestamp();
            } catch (\Exception) {
                continue;
            }
            if ($seconds <= 0) {
                continue;
            }
            if (!isset($scores[$userId]) || $seconds < $scores[$userId]) {
                $scores[$userId] = $seconds;
            }
        }

        $userIds = array_keys($scores);
        if ([] === $userIds) {
            return [[], 0];
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $userRows = $this->connection->fetchAllAssociative(
            "SELECT id, COALESCE(slug, '') AS slug, display_name FROM identity_users WHERE id IN ({$placeholders})",
            $userIds,
        );

        $userMap = [];
        foreach ($userRows as $userRow) {
            $id = is_string($userRow['id'] ?? null) ? $userRow['id'] : '';
            if ('' !== $id) {
                $userMap[$id] = $userRow;
            }
        }

        /** @var list<array{slug: string, displayName: string, sortName: string, value: int}> $entries */
        $entries = [];
        foreach ($scores as $userId => $value) {
            $userRow = $userMap[$userId] ?? null;
            if (null === $userRow) {
                continue;
            }
            $slug = is_string($userRow['slug'] ?? null) ? $userRow['slug'] : '';
            $displayName = is_string($userRow['display_name'] ?? null) ? $userRow['display_name'] : '';
            $sortName = mb_strtolower('' !== $displayName ? $displayName : $slug);
            $entries[] = ['slug' => $slug, 'displayName' => $displayName, 'sortName' => $sortName, 'value' => $value];
        }

        usort($entries, static function (array $a, array $b): int {
            if ($a['value'] !== $b['value']) {
                return $a['value'] <=> $b['value'];
            }

            return strcmp($a['sortName'], $b['sortName']);
        });

        $total = count($entries);
        $pageEntries = array_slice($entries, $offset, $limit);

        $finalEntries = [];
        foreach ($pageEntries as $entry) {
            $finalEntries[] = ['slug' => $entry['slug'], 'displayName' => $entry['displayName'], 'value' => $entry['value']];
        }

        return [$finalEntries, $total];
    }
}
