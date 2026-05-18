<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use Doctrine\DBAL\Connection;

final readonly class WeeklyRunLeaderboardQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{
     *   fastest: list<array<string, mixed>>,
     *   fewestChecks: list<array<string, mixed>>,
     *   fewestItems: list<array<string, mixed>>,
     *   participants: list<array<string, mixed>>,
     * }
     */
    public function execute(string $weeklyRunId): array
    {
        $userTable = $this->connection->quoteSingleIdentifier('user');

        $rows = $this->connection->createQueryBuilder()
            ->select(
                'we.id AS entry_id',
                'we.user_id',
                'we.attempt_number',
                'we.goal_reached_at',
                'we.completion_time_seconds',
                'we.checks_total',
                'we.items_total',
                'u.display_name AS display_name',
            )
            ->from('weekly_entries', 'we')
            ->leftJoin('we', $userTable, 'u', 'u.id = we.user_id')
            ->where('we.weekly_run_id = :runId')
            ->setParameter('runId', $weeklyRunId)
            ->executeQuery()
            ->fetchAllAssociative();

        $participants = [];
        $withGoal = [];

        foreach ($rows as $row) {
            $entryId = is_string($row['entry_id']) ? $row['entry_id'] : '';
            $userId = is_string($row['user_id']) ? $row['user_id'] : '';
            $displayName = is_string($row['display_name']) ? $row['display_name'] : null;
            $attemptNumber = is_numeric($row['attempt_number']) ? (int) $row['attempt_number'] : 0;
            $goalReachedAt = is_string($row['goal_reached_at']) ? $row['goal_reached_at'] : null;
            $completionTimeSeconds = is_numeric($row['completion_time_seconds']) ? (int) $row['completion_time_seconds'] : null;
            $checksTotal = is_numeric($row['checks_total']) ? (int) $row['checks_total'] : null;
            $itemsTotal = is_numeric($row['items_total']) ? (int) $row['items_total'] : null;

            $participants[] = [
                'entryId' => $entryId,
                'userId' => $userId,
                'displayName' => $displayName,
                'attemptNumber' => $attemptNumber,
                'goalReachedAt' => $goalReachedAt,
            ];

            if (null !== $goalReachedAt) {
                $withGoal[] = [
                    'entryId' => $entryId,
                    'userId' => $userId,
                    'displayName' => $displayName,
                    'attemptNumber' => $attemptNumber,
                    'goalReachedAt' => $goalReachedAt,
                    'completionTimeSeconds' => $completionTimeSeconds,
                    'checksTotal' => $checksTotal,
                    'itemsTotal' => $itemsTotal,
                ];
            }
        }

        $fastest = $withGoal;
        usort($fastest, static fn (array $a, array $b): int => ($a['completionTimeSeconds'] ?? PHP_INT_MAX) <=> ($b['completionTimeSeconds'] ?? PHP_INT_MAX));

        $fewestChecks = $withGoal;
        usort($fewestChecks, static fn (array $a, array $b): int => ($a['checksTotal'] ?? PHP_INT_MAX) <=> ($b['checksTotal'] ?? PHP_INT_MAX));

        $fewestItems = $withGoal;
        usort($fewestItems, static fn (array $a, array $b): int => ($a['itemsTotal'] ?? PHP_INT_MAX) <=> ($b['itemsTotal'] ?? PHP_INT_MAX));

        return [
            'fastest' => $fastest,
            'fewestChecks' => $fewestChecks,
            'fewestItems' => $fewestItems,
            'participants' => $participants,
        ];
    }
}
