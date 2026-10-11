<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure\Dbal;

use App\WeeklyRuns\Application\Query\WeeklyRunFriendEntriesQueryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalWeeklyRunFriendEntriesQuery implements WeeklyRunFriendEntriesQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function entriesOf(string $weeklyRunId, array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('we.user_id', 'we.launched_at', 'we.goal_reached_at', 'we.completion_time_seconds')
            ->from('weekly_entries', 'we')
            ->where($qb->expr()->eq('we.weekly_run_id', ':runId'))
            ->andWhere($qb->expr()->in('we.user_id', ':ids'))
            ->setParameter('runId', $weeklyRunId)
            ->setParameter('ids', $userIds, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $entries = [];
        foreach ($rows as $row) {
            $userId = $row['user_id'] ?? null;
            if (!is_string($userId)) {
                continue;
            }
            $launchedAt = $row['launched_at'] ?? null;
            $goalAt = $row['goal_reached_at'] ?? null;
            $seconds = $row['completion_time_seconds'] ?? null;
            $entries[] = [
                'userId' => $userId,
                'launchedAt' => is_string($launchedAt) ? new \DateTimeImmutable($launchedAt)->format(\DateTimeInterface::ATOM) : null,
                'goalReachedAt' => is_string($goalAt) ? new \DateTimeImmutable($goalAt)->format(\DateTimeInterface::ATOM) : null,
                'completionTimeSeconds' => is_numeric($seconds) ? (int) $seconds : null,
            ];
        }

        return $entries;
    }
}
