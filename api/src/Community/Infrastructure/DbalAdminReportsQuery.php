<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Application\AdminReportsQueryInterface;
use App\Community\Application\ReportQueryFilters;
use Doctrine\DBAL\Connection;

final readonly class DbalAdminReportsQuery implements AdminReportsQueryInterface
{
    private string $userTable;

    public function __construct(private Connection $connection)
    {
        $this->userTable = $connection->quoteSingleIdentifier('user');
    }

    public function matchingIds(ReportQueryFilters $filters): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('r.id')
            ->from('community_content_report', 'r')
            ->leftJoin('r', 'community_profile_comment', 'c', "r.target_type = 'comment' AND c.id = r.target_id")
            ->leftJoin('c', $this->userTable, 'author', 'author.id = c.author_id');

        match ($filters->status) {
            ReportQueryFilters::STATUS_PENDING => $qb->andWhere('r.resolved_at IS NULL'),
            ReportQueryFilters::STATUS_RESOLVED => $qb->andWhere('r.resolved_at IS NOT NULL'),
            default => $qb,
        };

        // The comment-state filter only makes sense for comment-target reports, so it implicitly narrows
        // to those (profile-target reports have no comment to be hidden/visible).
        match ($filters->commentState) {
            ReportQueryFilters::COMMENT_HIDDEN => $qb->andWhere("r.target_type = 'comment' AND c.hidden_at IS NOT NULL"),
            ReportQueryFilters::COMMENT_VISIBLE => $qb->andWhere("r.target_type = 'comment' AND c.hidden_at IS NULL"),
            default => $qb,
        };

        if (ReportQueryFilters::TARGET_ANY !== $filters->targetType) {
            $qb->andWhere('r.target_type = :targetType')
                ->setParameter('targetType', $filters->targetType);
        }

        if ('' !== $filters->search) {
            $escaped = addcslashes($filters->search, '%_\\');
            $qb->andWhere('(c.body ILIKE :q OR r.reason ILIKE :q OR author.display_name ILIKE :q)')
                ->setParameter('q', '%'.$escaped.'%');
        }

        $direction = ReportQueryFilters::SORT_OLDEST === $filters->sort ? 'ASC' : 'DESC';
        $qb->orderBy('r.created_at', $direction)
            ->addOrderBy('r.id', $direction)
            ->setMaxResults($filters->limit);

        $ids = $qb->executeQuery()->fetchFirstColumn();

        return array_values(array_filter($ids, static fn (mixed $id): bool => is_string($id)));
    }
}
