<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure\Dbal;

use App\WeeklyRuns\Application\Query\WeeklyDuelContextQueryInterface;
use App\WeeklyRuns\Domain\Entity\WeeklyRun;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalWeeklyDuelContextQuery implements WeeklyDuelContextQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function runs(array $weeklyRunIds): array
    {
        if ([] === $weeklyRunIds) {
            return [];
        }
        $rows = $this->connection->createQueryBuilder()
            ->select('wr.id', 'wr.status', 'wr.started_at', 'g.name AS game_name')
            ->from('weekly_runs', 'wr')
            ->leftJoin('wr', 'weekly_templates', 'wt', 'wt.id = wr.template_id')
            ->leftJoin('wt', 'game', 'g', 'g.id = wt.game_id')
            ->where('wr.id IN (:ids)')
            ->setParameter('ids', $weeklyRunIds, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $runs = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $startedAt = $row['started_at'] ?? null;
            if (!is_string($id) || !is_string($startedAt)) {
                continue;
            }
            $gameName = $row['game_name'] ?? null;
            $runs[$id] = [
                'gameName' => is_string($gameName) ? $gameName : null,
                'active' => WeeklyRun::STATUS_ACTIVE === ($row['status'] ?? null),
                'startedAt' => new \DateTimeImmutable($startedAt),
            ];
        }

        return $runs;
    }

    public function finishedRunsWithOpenDuels(\DateTimeImmutable $finishedBefore): array
    {
        $ids = $this->connection->createQueryBuilder()
            ->select('DISTINCT wr.id')
            ->from('weekly_runs', 'wr')
            ->join('wr', 'weekly_duel', 'd', 'd.weekly_run_id = wr.id AND d.resolved_at IS NULL')
            ->where('wr.status = :finished')
            ->andWhere('wr.finished_at <= :before')
            ->setParameter('finished', WeeklyRun::STATUS_FINISHED)
            ->setParameter('before', $finishedBefore->format(\DateTimeInterface::ATOM))
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_filter($ids, is_string(...)));
    }

    public function blocksAmong(array $userIds): array
    {
        if (\count($userIds) < 2) {
            return [];
        }
        $rows = $this->connection->createQueryBuilder()
            ->select('b.blocker_id', 'b.blocked_id')
            ->from('community_block', 'b')
            ->where('b.blocker_id IN (:ids)')
            ->andWhere('b.blocked_id IN (:ids)')
            ->setParameter('ids', $userIds, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $pairs = [];
        foreach ($rows as $row) {
            $blocker = $row['blocker_id'] ?? null;
            $blocked = $row['blocked_id'] ?? null;
            if (is_string($blocker) && is_string($blocked)) {
                $pairs[] = [$blocker, $blocked];
            }
        }

        return $pairs;
    }
}
