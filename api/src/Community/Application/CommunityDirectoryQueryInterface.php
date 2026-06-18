<?php

declare(strict_types=1);

namespace App\Community\Application;

/**
 * Lightweight read model for the community directory (story 30.15, epic §K review #13): cheap list/rank
 * queries that never compose the full per-profile read. XP is derived in the application layer via the
 * canonical CommunityXp formula (review #8) from the components returned here.
 */
interface CommunityDirectoryQueryInterface
{
    /**
     * XP components per user (mirrors the Epic-18 PlayerStats definitions + achievement grant counts).
     *
     * @param list<string>|null $userIds null = every member with stats or achievements
     *
     * @return array<string, array{goalCompletions: int, totalChecksDone: int, runsParticipated: int, achievementsUnlocked: int}>
     */
    public function xpComponents(?array $userIds): array;

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
