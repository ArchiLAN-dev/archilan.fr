<?php

declare(strict_types=1);

namespace App\Membership\Infrastructure;

use App\Membership\Application\AdminMembershipListQueryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class DbalAdminMembershipListQuery implements AdminMembershipListQueryInterface
{
    private const STATUS_EXPR = "CASE WHEN m.status = 'cancelled' THEN 'cancelled' WHEN m.expires_at >= :nowSelect THEN 'active' ELSE 'expired' END";

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, limit: int, total: int}}
     */
    public function search(int $page, int $limit, ?string $status, ?string $search, ?string $userId = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $page = max(1, $page);
        $limit = min(200, max(1, $limit));
        $userTable = $this->connection->quoteSingleIdentifier('user');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select(
                'm.id',
                'm.user_id AS "userId"',
                'u.email',
                'u.display_name AS "displayName"',
                self::STATUS_EXPR.' AS "status"',
                'm.started_at AS "startedAt"',
                'm.expires_at AS "expiresAt"',
                'm.source',
                'm.helloasso_order_id AS "helloassoOrderId"',
                'm.admin_note AS "adminNote"',
            )
            ->from('memberships', 'm')
            ->innerJoin('m', $userTable, 'u', $qb->expr()->eq('u.id', 'm.user_id'))
            ->orderBy('m.started_at', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->setParameter('nowSelect', $now);

        $this->applyStatusFilter($qb, $status, $now);
        $this->applyCommonFilters($qb, $search, $userId, $dateFrom, $dateTo);

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $countQb = $this->connection->createQueryBuilder();
        $countQb
            ->select('COUNT(m.id)')
            ->from('memberships', 'm')
            ->innerJoin('m', $userTable, 'u', $countQb->expr()->eq('u.id', 'm.user_id'));

        $this->applyStatusFilter($countQb, $status, $now);
        $this->applyCommonFilters($countQb, $search, $userId, $dateFrom, $dateTo);

        $totalRaw = $countQb->executeQuery()->fetchOne();
        $total = is_numeric($totalRaw) ? (int) $totalRaw : 0;

        return [
            'data' => $rows,
            'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $membershipId): ?array
    {
        $userTable = $this->connection->quoteSingleIdentifier('user');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $qb = $this->connection->createQueryBuilder();
        $row = $qb
            ->select(
                'm.id',
                'm.user_id AS "userId"',
                'u.email',
                'u.display_name AS "displayName"',
                self::STATUS_EXPR.' AS "status"',
                'm.started_at AS "startedAt"',
                'm.expires_at AS "expiresAt"',
                'm.source',
                'm.helloasso_order_id AS "helloassoOrderId"',
                'm.admin_note AS "adminNote"',
            )
            ->from('memberships', 'm')
            ->innerJoin('m', $userTable, 'u', $qb->expr()->eq('u.id', 'm.user_id'))
            ->where($qb->expr()->eq('m.id', ':id'))
            ->setMaxResults(1)
            ->setParameter('id', $membershipId)
            ->setParameter('nowSelect', $now)
            ->executeQuery()
            ->fetchAssociative();

        return false !== $row ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestByUserId(string $userId): ?array
    {
        $userTable = $this->connection->quoteSingleIdentifier('user');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $qb = $this->connection->createQueryBuilder();
        $row = $qb
            ->select(
                'm.id',
                'm.user_id AS "userId"',
                'u.email',
                'u.display_name AS "displayName"',
                self::STATUS_EXPR.' AS "status"',
                'm.started_at AS "startedAt"',
                'm.expires_at AS "expiresAt"',
                'm.source',
                'm.helloasso_order_id AS "helloassoOrderId"',
                'm.admin_note AS "adminNote"',
            )
            ->from('memberships', 'm')
            ->innerJoin('m', $userTable, 'u', $qb->expr()->eq('u.id', 'm.user_id'))
            ->where($qb->expr()->eq('m.user_id', ':userId'))
            ->orderBy('m.started_at', 'DESC')
            ->setMaxResults(1)
            ->setParameter('userId', $userId)
            ->setParameter('nowSelect', $now)
            ->executeQuery()
            ->fetchAssociative();

        return false !== $row ? $row : null;
    }

    private function applyStatusFilter(QueryBuilder $qb, ?string $status, string $now): void
    {
        if (null === $status || '' === $status) {
            return;
        }

        match ($status) {
            'active' => $qb
                ->andWhere($qb->expr()->neq('m.status', ':cancelledStatus'))
                ->andWhere($qb->expr()->gte('m.expires_at', ':nowFilter'))
                ->setParameter('cancelledStatus', 'cancelled')
                ->setParameter('nowFilter', $now),
            'expired' => $qb
                ->andWhere($qb->expr()->neq('m.status', ':cancelledStatus'))
                ->andWhere($qb->expr()->lt('m.expires_at', ':nowFilter'))
                ->setParameter('cancelledStatus', 'cancelled')
                ->setParameter('nowFilter', $now),
            'cancelled' => $qb
                ->andWhere($qb->expr()->eq('m.status', ':cancelledStatus'))
                ->setParameter('cancelledStatus', 'cancelled'),
            default => $qb
                ->andWhere($qb->expr()->eq('m.status', ':statusFilter'))
                ->setParameter('statusFilter', $status),
        };
    }

    private function applyCommonFilters(QueryBuilder $qb, ?string $search, ?string $userId, ?string $dateFrom, ?string $dateTo): void
    {
        if (null !== $search && '' !== $search) {
            $qb->andWhere($qb->expr()->or(
                $qb->expr()->like('LOWER(u.email)', ':search'),
                $qb->expr()->like('LOWER(u.display_name)', ':search'),
            ))->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        if (null !== $userId && '' !== $userId) {
            $qb->andWhere($qb->expr()->eq('m.user_id', ':userId'))
               ->setParameter('userId', $userId);
        }

        if (null !== $dateFrom && '' !== $dateFrom) {
            $qb->andWhere($qb->expr()->gte('m.started_at', ':dateFrom'))
               ->setParameter('dateFrom', $dateFrom);
        }

        if (null !== $dateTo && '' !== $dateTo) {
            $qb->andWhere($qb->expr()->lte('m.started_at', ':dateTo'))
               ->setParameter('dateTo', $dateTo);
        }
    }
}
