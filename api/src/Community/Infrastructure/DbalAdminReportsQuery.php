<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Application\AdminReportsQueryInterface;
use App\Community\Application\ReportQueryFilters;
use App\Community\Domain\ReportCategory;
use App\Community\Domain\ReportProblem;
use App\Community\Domain\ReportSeverity;
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

        if (ReportQueryFilters::PROBLEM_ANY !== $filters->problem) {
            $qb->andWhere('r.problem = :problem')
                ->setParameter('problem', $filters->problem);
        }

        // The low-signal "Autre / Autre / sans commentaire" bucket, surfaced only on demand.
        if ($filters->uncategorizedOnly) {
            $qb->andWhere('r.category = :uncatCategory')
                ->andWhere('r.problem = :uncatProblem')
                ->andWhere("(r.report_comment IS NULL OR r.report_comment = '')")
                ->setParameter('uncatCategory', ReportCategory::OTHER)
                ->setParameter('uncatProblem', ReportProblem::OTHER);
        }

        if ('' !== $filters->search) {
            $escaped = addcslashes($filters->search, '%_\\');
            $qb->andWhere('(c.body ILIKE :q OR r.reason ILIKE :q OR author.display_name ILIKE :q)')
                ->setParameter('q', '%'.$escaped.'%');
        }

        if (ReportQueryFilters::SORT_SEVERITY === $filters->sort) {
            // Most problematic first: map each problem to its domain weight via a CASE, then recency.
            $qb->orderBy($this->severityCase(), 'DESC')
                ->addOrderBy('r.created_at', 'DESC')
                ->addOrderBy('r.id', 'DESC');
        } else {
            $direction = ReportQueryFilters::SORT_OLDEST === $filters->sort ? 'ASC' : 'DESC';
            $qb->orderBy('r.created_at', $direction)
                ->addOrderBy('r.id', $direction);
        }
        $qb->setMaxResults($filters->limit);

        $ids = $qb->executeQuery()->fetchFirstColumn();

        return array_values(array_filter($ids, static fn (mixed $id): bool => is_string($id)));
    }

    /**
     * Builds `CASE r.problem WHEN 'nudity' THEN 10 ... ELSE 0 END` from the domain severity map, so the
     * "most problematic first" sort stays in sync with {@see ReportSeverity}. Keys are fixed enum strings
     * and values are ints, so the literal SQL is safe.
     */
    private function severityCase(): string
    {
        $whens = '';
        foreach (ReportSeverity::WEIGHTS as $problem => $weight) {
            $whens .= sprintf(" WHEN '%s' THEN %d", $problem, $weight);
        }

        return 'CASE r.problem'.$whens.' ELSE 0 END';
    }
}
