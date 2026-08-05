<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\CommunityDirectoryQueryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Lightweight directory reads (story 30.15): the listable candidate set and per-user last activity.
 * Level/XP are not computed here - the directory resolves them via the shared CommunityLevelQuery so
 * every surface agrees.
 */
final readonly class DbalCommunityDirectoryQuery implements CommunityDirectoryQueryInterface
{
    private string $userTable;

    public function __construct(private Connection $connection)
    {
        $this->userTable = $connection->quoteSingleIdentifier('user');
    }

    public function listableIds(?string $search): array
    {
        $term = null === $search ? '' : trim($search);

        // Match + sort on the community pseudo (override) falling back to the account name.
        $name = 'COALESCE(cp.display_name, u.display_name)';

        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('u.id AS uid')
            ->from($this->userTable, 'u')
            ->leftJoin('u', 'community_profile', 'cp', 'cp.user_id = u.id')
            ->where($qb->expr()->isNull('u.deleted_at'))
            ->andWhere('u.slug IS NOT NULL')
            ->orderBy($name, 'ASC')
            ->addOrderBy('u.slug', 'ASC');

        if ('' !== $term) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $qb
                ->andWhere($qb->expr()->or(
                    $qb->expr()->comparison('u.slug', 'ILIKE', ':like'),
                    $qb->expr()->comparison($name, 'ILIKE', ':like'),
                ))
                ->setParameter('like', $like);
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $ids = [];
        foreach ($rows as $row) {
            if (is_string($row['uid'] ?? null)) {
                $ids[] = $row['uid'];
            }
        }

        return $ids;
    }

    public function lastActivityAt(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('a.actor_id AS uid', 'MAX(a.occurred_at) AS last_at')
            ->from('community_activity_entry', 'a')
            ->where($qb->expr()->in('a.actor_id', ':ids'))
            ->setParameter('ids', $userIds, ArrayParameterType::STRING)
            ->groupBy('a.actor_id')
            ->executeQuery()
            ->fetchAllAssociative();

        $lastAt = [];
        foreach ($rows as $row) {
            $userId = $row['uid'] ?? null;
            $at = $row['last_at'] ?? null;
            if (is_string($userId) && is_string($at)) {
                $lastAt[$userId] = $at;
            }
        }

        return $lastAt;
    }

    public function listableMemberCount(): int
    {
        $qb = $this->connection->createQueryBuilder();
        $count = $qb
            ->select('COUNT(u.id)')
            ->from($this->userTable, 'u')
            ->where('u.slug IS NOT NULL')
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }
}
