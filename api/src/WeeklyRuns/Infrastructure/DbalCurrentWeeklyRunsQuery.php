<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\WeeklyRuns\Application\CurrentWeeklyRunsQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalCurrentWeeklyRunsQuery implements CurrentWeeklyRunsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function execute(?string $myUserId): array
    {
        $userTable = $this->connection->quoteSingleIdentifier('user');

        $runRows = $this->connection->createQueryBuilder()
            ->select(
                'wr.id AS run_id',
                'wr.week_number',
                'wr.week_year',
                'wr.status',
                'wr.started_at',
                'wr.finished_at',
                'wt.name AS template_name',
                'g.name AS game_name',
            )
            ->from('weekly_runs', 'wr')
            ->join('wr', 'weekly_templates', 'wt', 'wt.id = wr.template_id')
            ->join('wr', 'game', 'g', 'g.id = wt.game_id')
            ->where('wr.status = :active')
            ->setParameter('active', 'active')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($runRows as $runRow) {
            $runId = is_string($runRow['run_id']) ? $runRow['run_id'] : '';

            $entryRows = $this->connection->createQueryBuilder()
                ->select(
                    'we.id AS entry_id',
                    'we.user_id',
                    'we.attempt_number',
                    'we.goal_reached_at',
                    'we.completion_time_seconds',
                    'we.checks_total',
                    'we.items_total',
                    'we.external_session_id',
                    'we.launched_at',
                    'we.connection_host',
                    'we.connection_port',
                    'we.connection_password',
                    'u.display_name AS display_name',
                )
                ->from('weekly_entries', 'we')
                ->leftJoin('we', $userTable, 'u', 'u.id = we.user_id')
                ->where('we.weekly_run_id = :runId')
                ->setParameter('runId', $runId)
                ->executeQuery()
                ->fetchAllAssociative();

            $participants = [];
            $withGoal = [];
            $myEntry = null;

            foreach ($entryRows as $row) {
                $entryId = is_string($row['entry_id']) ? $row['entry_id'] : '';
                $userId = is_string($row['user_id']) ? $row['user_id'] : '';
                $displayName = is_string($row['display_name']) ? $row['display_name'] : null;
                $attemptNumber = is_numeric($row['attempt_number']) ? (int) $row['attempt_number'] : 0;
                $goalReachedAt = is_string($row['goal_reached_at']) ? $row['goal_reached_at'] : null;
                $completionTimeSeconds = is_numeric($row['completion_time_seconds']) ? (int) $row['completion_time_seconds'] : null;
                $checksTotal = is_numeric($row['checks_total']) ? (int) $row['checks_total'] : null;
                $itemsTotal = is_numeric($row['items_total']) ? (int) $row['items_total'] : null;
                $externalSessionId = is_string($row['external_session_id']) ? $row['external_session_id'] : null;
                $launchedAt = is_string($row['launched_at']) ? $row['launched_at'] : null;
                $connectionHost = is_string($row['connection_host']) ? $row['connection_host'] : null;
                $connectionPort = is_numeric($row['connection_port']) ? (int) $row['connection_port'] : null;
                $connectionPassword = is_string($row['connection_password']) ? $row['connection_password'] : null;

                $participants[] = [
                    'entryId' => $entryId,
                    'userId' => $userId,
                    'displayName' => $displayName,
                    'attemptNumber' => $attemptNumber,
                    'goalReachedAt' => $goalReachedAt,
                    'connectionInfo' => null !== $externalSessionId && null !== $connectionHost && null !== $connectionPort
                        ? [
                            'host' => $connectionHost,
                            'port' => $connectionPort,
                            'password' => $connectionPassword,
                        ]
                        : null,
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

                if (null !== $myUserId && $userId === $myUserId) {
                    $myEntry = [
                        'entryId' => $entryId,
                        'externalSessionId' => $externalSessionId,
                        'launchedAt' => $launchedAt,
                        'goalReachedAt' => $goalReachedAt,
                        'connectionInfo' => null,
                    ];
                    if (null !== $externalSessionId && null !== $connectionHost && null !== $connectionPort) {
                        $myEntry['connectionInfo'] = [
                            'host' => $connectionHost,
                            'port' => $connectionPort,
                            'password' => $connectionPassword,
                        ];
                    }
                }
            }

            $fastest = $withGoal;
            usort($fastest, static fn (array $a, array $b): int => ($a['completionTimeSeconds'] ?? PHP_INT_MAX) <=> ($b['completionTimeSeconds'] ?? PHP_INT_MAX));

            $fewestChecks = $withGoal;
            usort($fewestChecks, static fn (array $a, array $b): int => ($a['checksTotal'] ?? PHP_INT_MAX) <=> ($b['checksTotal'] ?? PHP_INT_MAX));

            $fewestItems = $withGoal;
            usort($fewestItems, static fn (array $a, array $b): int => ($a['itemsTotal'] ?? PHP_INT_MAX) <=> ($b['itemsTotal'] ?? PHP_INT_MAX));

            $result[] = [
                'weeklyRunId' => $runId,
                'templateName' => is_string($runRow['template_name']) ? $runRow['template_name'] : null,
                'gameName' => is_string($runRow['game_name']) ? $runRow['game_name'] : null,
                'weekNumber' => is_numeric($runRow['week_number']) ? (int) $runRow['week_number'] : 0,
                'weekYear' => is_numeric($runRow['week_year']) ? (int) $runRow['week_year'] : 0,
                'status' => is_string($runRow['status']) ? $runRow['status'] : '',
                'startedAt' => is_string($runRow['started_at']) ? $runRow['started_at'] : null,
                'finishedAt' => is_string($runRow['finished_at']) ? $runRow['finished_at'] : null,
                'leaderboard' => [
                    'fastest' => $fastest,
                    'fewestChecks' => $fewestChecks,
                    'fewestItems' => $fewestItems,
                ],
                'participants' => $participants,
                'myEntry' => $myEntry,
            ];
        }

        return $result;
    }
}
