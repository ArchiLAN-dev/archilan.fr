<?php

declare(strict_types=1);

namespace App\Community\Domain;

interface ContentReportRepositoryInterface
{
    public function exists(string $reporterId, string $targetType, string $targetId): bool;

    public function save(ContentReport $report): void;
}
