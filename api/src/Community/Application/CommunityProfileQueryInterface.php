<?php

declare(strict_types=1);

namespace App\Community\Application;

interface CommunityProfileQueryInterface
{
    /**
     * Composes the enriched profile read model for a public slug: identity (from `Identity`) + aggregate
     * stats (reused from Epic 18's `PlayerStatsQueryInterface`). Returns null when no live user matches.
     *
     * @return array{
     *     userId: string,
     *     slug: string,
     *     displayName: string|null,
     *     joinedAt: string,
     *     stats: array{
     *         runsParticipated: int,
     *         goalCompletions: int,
     *         goalCompletionRate: float,
     *         totalChecksDone: int,
     *         totalItemsReceived: int
     *     }
     * }|null
     */
    public function forSlug(string $slug): ?array;
}
