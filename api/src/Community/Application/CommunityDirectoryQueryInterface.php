<?php

declare(strict_types=1);

namespace App\Community\Application;

/**
 * Lightweight read model for the community directory (story 30.15, epic §K review #13): cheap list/search
 * queries that never compose the full per-profile read. Level/XP are resolved separately via the shared
 * CommunityLevelQuery so every surface reports the same number.
 */
interface CommunityDirectoryQueryInterface
{
    /**
     * User ids ordered by most recent activity-feed entry, paginated.
     *
     * @return array{ids: list<string>, total: int}
     */
    public function recentlyActive(int $limit, int $offset): array;

    /**
     * User ids whose slug or display name matches the term (case-insensitive), paginated.
     *
     * @return array{ids: list<string>, total: int}
     */
    public function search(string $term, int $limit, int $offset): array;
}
