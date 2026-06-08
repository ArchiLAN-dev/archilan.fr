<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\WeeklyRuns\Application\AdminTemplateRunsQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalAdminTemplateRunsQuery implements AdminTemplateRunsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function execute(string $templateId): array
    {
        $userTable = $this->connection->quoteSingleIdentifier('user');

        $runRows = $this->connection->createQueryBuilder()
            ->select(
                'wr.id AS run_id',
                'wr.status',
                'wr.seed',
                'wr.week_year',
                'wr.week_number',
                'wr.started_at',
                'wr.finished_at',
                'wr.generated_output_key',
                'wt.name AS template_name',
                'wt.game_id AS game_id',
                'g.name AS game_name',
            )
            ->from('weekly_runs', 'wr')
            ->join('wr', 'weekly_templates', 'wt', 'wt.id = wr.template_id')
            ->join('wr', 'game', 'g', 'g.id = wt.game_id')
            ->where('wr.template_id = :templateId')
            ->setParameter('templateId', $templateId)
            ->orderBy('wr.week_year', 'DESC')
            ->addOrderBy('wr.week_number', 'DESC')
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
                    'u.display_name AS display_name',
                )
                ->from('weekly_entries', 'we')
                ->leftJoin('we', $userTable, 'u', 'u.id = we.user_id')
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

            $generatedOutputKey = is_string($runRow['generated_output_key']) ? $runRow['generated_output_key'] : null;

            $result[] = [
                'weeklyRunId' => $runId,
                'hasOutput' => null !== $generatedOutputKey && '' !== $generatedOutputKey,
                'templateName' => is_string($runRow['template_name']) ? $runRow['template_name'] : null,
                'gameId' => is_string($runRow['game_id']) ? $runRow['game_id'] : '',
                'gameName' => is_string($runRow['game_name']) ? $runRow['game_name'] : '',
                'status' => is_string($runRow['status']) ? $runRow['status'] : '',
                'seed' => is_string($runRow['seed']) ? $runRow['seed'] : '',
                'weekYear' => is_numeric($runRow['week_year']) ? (int) $runRow['week_year'] : 0,
                'weekNumber' => is_numeric($runRow['week_number']) ? (int) $runRow['week_number'] : 0,
                'startedAt' => is_string($runRow['started_at']) ? $runRow['started_at'] : null,
                'finishedAt' => is_string($runRow['finished_at']) ? $runRow['finished_at'] : null,
                'entryCount' => count($entries),
                'entries' => $entries,
            ];
        }

        return $result;
    }
}
