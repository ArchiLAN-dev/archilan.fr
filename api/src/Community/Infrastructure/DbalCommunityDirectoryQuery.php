<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Application\CommunityDirectoryQueryInterface;
use Doctrine\DBAL\Connection;

/**
 * Lightweight directory reads (story 30.15): recently-active and search id lists. Level/XP are no longer
 * computed here - the directory resolves them via the shared CommunityLevelQuery so every surface agrees.
 */
final readonly class DbalCommunityDirectoryQuery implements CommunityDirectoryQueryInterface
{
    private string $userTable;

    public function __construct(private Connection $connection)
    {
        $this->userTable = $connection->quoteSingleIdentifier('user');
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

        // Match + sort on the community pseudo (override) falling back to the account name.
        $name = 'COALESCE(cp.display_name, u.display_name)';

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('u.id AS uid')
            ->from($this->userTable, 'u')
            ->leftJoin('u', 'community_profile', 'cp', 'cp.user_id = u.id')
            ->where($qb->expr()->isNull('u.deleted_at'))
            ->andWhere('u.slug IS NOT NULL')
            ->andWhere($qb->expr()->or($qb->expr()->comparison('u.slug', 'ILIKE', ':like'), $qb->expr()->comparison($name, 'ILIKE', ':like')))
            ->setParameter('like', $like)
            ->orderBy($name, 'ASC')
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
            ->leftJoin('u', 'community_profile', 'cp', 'cp.user_id = u.id')
            ->where($countQb->expr()->isNull('u.deleted_at'))
            ->andWhere('u.slug IS NOT NULL')
            ->andWhere($countQb->expr()->or($countQb->expr()->comparison('u.slug', 'ILIKE', ':like'), $countQb->expr()->comparison($name, 'ILIKE', ':like')))
            ->setParameter('like', $like)
            ->executeQuery()
            ->fetchOne();

        return ['ids' => $ids, 'total' => is_numeric($total) ? (int) $total : 0];
    }
}
