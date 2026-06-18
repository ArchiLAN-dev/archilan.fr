<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Application\CommunityDirectoryQueryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Lightweight directory reads (story 30.15). The stat aggregates mirror DbalPlayerStatsQuery's definitions
 * (finished sessions, released-without-goal slots excluded) but in batch, GROUP BY user; XP itself is
 * computed in the application layer from these components via CommunityXp (single source of truth).
 */
final readonly class DbalCommunityDirectoryQuery implements CommunityDirectoryQueryInterface
{
    private string $userTable;

    public function __construct(private Connection $connection)
    {
        $this->userTable = $connection->quoteSingleIdentifier('user');
    }

    public function xpComponents(?array $userIds): array
    {
        if (null !== $userIds && [] === $userIds) {
            return [];
        }

        $components = [];
        // Event sessions (slot -> registration -> user) and personal runs (slot.registration_id = user).
        foreach ($this->statsRows('reg.user_id', true, $userIds) as $row) {
            $this->accumulate($components, $row);
        }
        foreach ($this->statsRows('slot.registration_id', false, $userIds) as $row) {
            $this->accumulate($components, $row);
        }

        foreach ($this->achievementCounts($userIds) as $userId => $count) {
            $components[$userId]['achievementsUnlocked'] = $count;
        }

        // Ensure every key set has the full shape.
        $out = [];
        foreach ($components as $userId => $c) {
            $out[$userId] = [
                'goalCompletions' => $c['goalCompletions'] ?? 0,
                'totalChecksDone' => $c['totalChecksDone'] ?? 0,
                'runsParticipated' => $c['runsParticipated'] ?? 0,
                'achievementsUnlocked' => $c['achievementsUnlocked'] ?? 0,
            ];
        }

        return $out;
    }

    public function recentlyActive(int $limit, int $offset): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('a.actor_id AS uid', 'MAX(a.occurred_at) AS last_at')
            ->from('community_activity_entry', 'a')
            ->join('a', $this->userTable, 'u', $qb->expr()->eq('u.id', 'a.actor_id'))
            ->where('u.slug IS NOT NULL')
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->groupBy('a.actor_id')
            ->orderBy('last_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();

        $ids = [];
        foreach ($rows as $row) {
            if (is_string($row['uid'] ?? null)) {
                $ids[] = $row['uid'];
            }
        }

        $countQb = $this->connection->createQueryBuilder();
        $total = $countQb
            ->select('COUNT(DISTINCT a.actor_id)')
            ->from('community_activity_entry', 'a')
            ->join('a', $this->userTable, 'u', $countQb->expr()->eq('u.id', 'a.actor_id'))
            ->where('u.slug IS NOT NULL')
            ->andWhere($countQb->expr()->isNull('u.deleted_at'))
            ->executeQuery()
            ->fetchOne();

        return ['ids' => $ids, 'total' => is_numeric($total) ? (int) $total : 0];
    }

    public function search(string $term, int $limit, int $offset): array
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('u.id AS uid')
            ->from($this->userTable, 'u')
            ->where($qb->expr()->isNull('u.deleted_at'))
            ->andWhere('u.slug IS NOT NULL')
            ->andWhere($qb->expr()->or($qb->expr()->comparison('u.slug', 'ILIKE', ':like'), $qb->expr()->comparison('u.display_name', 'ILIKE', ':like')))
            ->setParameter('like', $like)
            ->orderBy('u.display_name', 'ASC')
            ->addOrderBy('u.slug', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();

        $ids = [];
        foreach ($rows as $row) {
            if (is_string($row['uid'] ?? null)) {
                $ids[] = $row['uid'];
            }
        }

        $countQb = $this->connection->createQueryBuilder();
        $total = $countQb
            ->select('COUNT(u.id)')
            ->from($this->userTable, 'u')
            ->where($countQb->expr()->isNull('u.deleted_at'))
            ->andWhere('u.slug IS NOT NULL')
            ->andWhere($countQb->expr()->or($countQb->expr()->comparison('u.slug', 'ILIKE', ':like'), $countQb->expr()->comparison('u.display_name', 'ILIKE', ':like')))
            ->setParameter('like', $like)
            ->executeQuery()
            ->fetchOne();

        return ['ids' => $ids, 'total' => is_numeric($total) ? (int) $total : 0];
    }

    /**
     * @param list<string>|null $userIds
     *
     * @return list<array<string, mixed>>
     */
    private function statsRows(string $userExpr, bool $eventPath, ?array $userIds): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select(
                $userExpr.' AS uid',
                'COUNT(DISTINCT s.id) AS runs',
                'COUNT(DISTINCT CASE WHEN slot.goal_reached_at IS NOT NULL THEN s.id END) AS goals',
                'COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL) THEN slot.checks_done ELSE 0 END), 0) AS checks',
            )
            ->from('session_slot', 'slot')
            ->join('slot', 'session', 's', $qb->expr()->eq('s.id', 'slot.session_id'))
            ->where($qb->expr()->eq('s.status', ':status'))
            ->setParameter('status', 'finished')
            ->groupBy($userExpr);

        if ($eventPath) {
            $qb->join('slot', 'registration', 'reg', $qb->expr()->eq('reg.id', 'slot.registration_id'));
        } else {
            $qb->join('s', 'run', 'pr', $qb->expr()->eq('pr.session_id', 's.id'));
        }

        // Only listable members (have a profile slug, not deleted) so directory totals match the rows.
        $qb->join('slot', $this->userTable, 'u', $qb->expr()->eq('u.id', $userExpr))
            ->andWhere('u.slug IS NOT NULL')
            ->andWhere($qb->expr()->isNull('u.deleted_at'));

        if (null !== $userIds) {
            $qb->andWhere($qb->expr()->in($userExpr, ':ids'))->setParameter('ids', $userIds, ArrayParameterType::STRING);
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param list<string>|null $userIds
     *
     * @return array<string, int>
     */
    private function achievementCounts(?array $userIds): array
    {
        if (null !== $userIds && [] === $userIds) {
            return [];
        }

        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('g.user_id AS uid', 'COUNT(g.id) AS cnt')
            ->from('community_achievement_grant', 'g')
            ->join('g', $this->userTable, 'u', $qb->expr()->eq('u.id', 'g.user_id'))
            ->andWhere('u.slug IS NOT NULL')
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->groupBy('g.user_id');

        if (null !== $userIds) {
            $qb->andWhere($qb->expr()->in('g.user_id', ':ids'))->setParameter('ids', $userIds, ArrayParameterType::STRING);
        }

        $counts = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $uid = $row['uid'] ?? null;
            if (is_string($uid)) {
                $counts[$uid] = is_numeric($row['cnt'] ?? null) ? (int) $row['cnt'] : 0;
            }
        }

        return $counts;
    }

    /**
     * @param array<string, array{goalCompletions?: int, totalChecksDone?: int, runsParticipated?: int, achievementsUnlocked?: int}> $components
     * @param array<string, mixed>                                                                                                   $row
     */
    private function accumulate(array &$components, array $row): void
    {
        $uid = $row['uid'] ?? null;
        if (!is_string($uid)) {
            return;
        }
        $components[$uid]['goalCompletions'] = ($components[$uid]['goalCompletions'] ?? 0) + $this->intVal($row, 'goals');
        $components[$uid]['totalChecksDone'] = ($components[$uid]['totalChecksDone'] ?? 0) + $this->intVal($row, 'checks');
        $components[$uid]['runsParticipated'] = ($components[$uid]['runsParticipated'] ?? 0) + $this->intVal($row, 'runs');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function intVal(array $row, string $key): int
    {
        return is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;
    }
}
