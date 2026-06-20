<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Application\AccountReportScoreQueryInterface;
use App\Community\Domain\ContentReport;
use Doctrine\DBAL\Connection;

final readonly class DbalAccountReportScoreQuery implements AccountReportScoreQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function unresolvedProblemsForAccount(string $accountUserId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('r.problem')
            ->from('community_content_report', 'r')
            ->where('r.target_type = :type')
            ->andWhere('r.target_id = :account')
            ->andWhere('r.resolved_at IS NULL')
            ->setParameter('type', ContentReport::TARGET_PROFILE)
            ->setParameter('account', $accountUserId)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_filter($rows, static fn (mixed $p): bool => is_string($p)));
    }

    public function unresolvedProblemsByAccount(): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('r.target_id', 'r.problem')
            ->from('community_content_report', 'r')
            ->where('r.target_type = :type')
            ->andWhere('r.resolved_at IS NULL')
            ->setParameter('type', ContentReport::TARGET_PROFILE)
            ->executeQuery()
            ->fetchAllAssociative();

        $byAccount = [];
        foreach ($rows as $row) {
            $account = $row['target_id'] ?? null;
            $problem = $row['problem'] ?? null;
            if (!is_string($account) || !is_string($problem)) {
                continue;
            }
            $byAccount[$account][] = $problem;
        }

        return $byAccount;
    }
}
