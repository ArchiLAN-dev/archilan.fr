<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\WeeklyRuns\Application\WeeklyRunEntriesQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalWeeklyRunEntriesQuery implements WeeklyRunEntriesQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function findByRunId(string $weeklyRunId): ?array
    {
        $userTable = $this->connection->quoteSingleIdentifier('user');

        $runExists = $this->connection->createQueryBuilder()
            ->select('1')
            ->from('weekly_runs', 'wr')
            ->where('wr.id = :runId')
            ->setParameter('runId', $weeklyRunId)
            ->executeQuery()
            ->fetchOne();

        if (false === $runExists) {
            return null;
        }

        $rows = $this->connection->createQueryBuilder()
            ->select(
                'we.user_id',
                'we.attempt_number',
                'we.external_session_id',
                'we.launched_at',
                'we.goal_reached_at',
                'we.completion_time_seconds',
                'we.checks_total',
                'we.items_total',
                'COALESCE(cp.display_name, u.display_name) AS display_name',
            )
            ->from('weekly_entries', 'we')
            ->leftJoin('we', $userTable, 'u', 'u.id = we.user_id')
            ->leftJoin('u', 'community_profile', 'cp', 'cp.user_id = u.id')
            ->where('we.weekly_run_id = :runId')
            ->orderBy('we.launched_at', 'ASC')
            ->setParameter('runId', $weeklyRunId)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'userId' => is_string($row['user_id']) ? $row['user_id'] : '',
            'displayName' => is_string($row['display_name']) ? $row['display_name'] : null,
            'attemptNumber' => is_numeric($row['attempt_number']) ? (int) $row['attempt_number'] : 0,
            'externalSessionId' => is_string($row['external_session_id']) ? $row['external_session_id'] : null,
            'launchedAt' => is_string($row['launched_at']) ? $row['launched_at'] : null,
            'goalReachedAt' => is_string($row['goal_reached_at']) ? $row['goal_reached_at'] : null,
            'completionTimeSeconds' => is_numeric($row['completion_time_seconds']) ? (int) $row['completion_time_seconds'] : null,
            'checksTotal' => is_numeric($row['checks_total']) ? (int) $row['checks_total'] : null,
            'itemsTotal' => is_numeric($row['items_total']) ? (int) $row['items_total'] : null,
        ], $rows);
    }
}
