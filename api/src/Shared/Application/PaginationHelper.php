<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Doctrine\DBAL\Query\QueryBuilder;

final class PaginationHelper
{
    public static function applyTo(QueryBuilder $qb, int $page, int $limit, int $minLimit = 1, int $maxLimit = 100): void
    {
        $limit = max($minLimit, min($maxLimit, $limit));
        $page = max(1, $page);

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);
    }
}
