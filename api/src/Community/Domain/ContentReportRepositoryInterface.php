<?php

declare(strict_types=1);

namespace App\Community\Domain;

interface ContentReportRepositoryInterface
{
    public function exists(string $reporterId, string $targetType, string $targetId): bool;

    public function findById(string $id): ?ContentReport;

    /**
     * Unresolved reports for the moderation queue, oldest first (FIFO).
     *
     * @return list<ContentReport>
     */
    public function pending(int $limit): array;

    public function countPending(): int;

    public function save(ContentReport $report): void;

    public function flush(): void;
}
