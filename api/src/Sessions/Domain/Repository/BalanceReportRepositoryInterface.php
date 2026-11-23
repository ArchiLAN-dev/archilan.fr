<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Repository;

use App\Sessions\Domain\Entity\BalanceReport;

/** Story 56.3: the last dry generation of each event and private run. */
interface BalanceReportRepositoryInterface
{
    public function find(string $scopeType, string $scopeId): ?BalanceReport;

    public function save(BalanceReport $report): void;
}
