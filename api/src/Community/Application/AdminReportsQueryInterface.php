<?php

declare(strict_types=1);

namespace App\Community\Application;

/**
 * Resolves the IDs of content reports matching the admin moderation filters (story 30.25). The DTO
 * assembly (comment + parties) stays in {@see ModerationService}; this only does the DB-level
 * filtering/search/sort so the ID list comes back already ordered.
 */
interface AdminReportsQueryInterface
{
    /**
     * @return list<string> report IDs in display order
     */
    public function matchingIds(ReportQueryFilters $filters): array;
}
