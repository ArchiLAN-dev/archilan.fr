<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\WeeklyRuns\Application\AdminCurrentWeeklyRunsQueryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

final readonly class DbalAdminCurrentWeeklyRunsQuery implements AdminCurrentWeeklyRunsQueryInterface
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function execute(): array
    {
        $userTable = $this->connection->quoteSingleIdentifier('user');

        $now = $this->clock->now();
        $weekYear = (int) $now->format('o');
        $weekNumber = (int) $now->format('W');

        $runRows = $this->connection->createQueryBuilder()
            ->select(
                'wr.id AS run_id',
                'wr.status',
                'wr.seed',
                'wr.started_at',
                'wr.finished_at',
                'wt.name AS template_name',
                'wt.game_id AS game_id',
                'g.name AS game_name',
            )
            ->from('weekly_runs', 'wr')
            ->join('wr', 'weekly_templates', 'wt', 'wt.id = wr.template_id')
            ->join('wr', 'game', 'g', 'g.id = wt.game_id')
            ->where('wr.week_year = :weekYear')
            ->andWhere('wr.week_number = :weekNumber')
            ->andWhere('wr.status IN (:statuses)')
            ->setParameter('statuses', ['active', 'finished'], ArrayParameterType::STRING)
            ->setParameter('weekYear', $weekYear)
            ->setParameter('weekNumber', $weekNumber)
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($runRows as $runRow) {
            $runId = is_string($runRow['run_id']) ? $runRow['run_id'] : '';

            $entryRows = $this->connection->createQueryBuilder()
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
                ->setParameter('runId', $runId)
                ->executeQuery()
                ->fetchAllAssociative();

            $entries = array_map(static fn (array $row): array => [
                'userId' => is_string($row['user_id']) ? $row['user_id'] : '',
                'displayName' => is_string($row['display_name']) ? $row['display_name'] : '',
                'attemptNumber' => is_numeric($row['attempt_number']) ? (int) $row['attempt_number'] : 0,
                'externalSessionId' => is_string($row['external_session_id']) ? $row['external_session_id'] : null,
                'launchedAt' => is_string($row['launched_at']) ? $row['launched_at'] : null,
                'goalReachedAt' => is_string($row['goal_reached_at']) ? $row['goal_reached_at'] : null,
                'completionTimeSeconds' => is_numeric($row['completion_time_seconds']) ? (int) $row['completion_time_seconds'] : null,
                'checksTotal' => is_numeric($row['checks_total']) ? (int) $row['checks_total'] : null,
                'itemsTotal' => is_numeric($row['items_total']) ? (int) $row['items_total'] : null,
            ], $entryRows);

            $result[] = [
                'weeklyRunId' => $runId,
                'templateName' => is_string($runRow['template_name']) ? $runRow['template_name'] : null,
                'gameId' => is_string($runRow['game_id']) ? $runRow['game_id'] : '',
                'gameName' => is_string($runRow['game_name']) ? $runRow['game_name'] : '',
                'status' => is_string($runRow['status']) ? $runRow['status'] : '',
                'seed' => is_string($runRow['seed']) ? $runRow['seed'] : '',
                'startedAt' => is_string($runRow['started_at']) ? $runRow['started_at'] : null,
                'finishedAt' => is_string($runRow['finished_at']) ? $runRow['finished_at'] : null,
                'entryCount' => count($entries),
                'entries' => $entries,
            ];
        }

        return $result;
    }
}
