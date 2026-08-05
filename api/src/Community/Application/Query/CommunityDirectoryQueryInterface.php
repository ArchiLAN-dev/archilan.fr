<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * Lightweight read model for the community directory (story 30.15, epic §K review #13): cheap list/search
 * queries that never compose the full per-profile read. Level/XP are resolved separately via the shared
 * CommunityLevelQuery so every surface reports the same number.
 *
 * Story 30.38 replaced the old "one method per tab" shape (recentlyActive / search, each paginating on its
 * own) with a candidate-set shape. The directory now composes a search, a sort and a friends filter, which
 * a per-tab paginated query cannot express: the term has to narrow whichever set the other two produced,
 * not replace it.
 */
interface CommunityDirectoryQueryInterface
{
    /**
     * Ids of every listable member (a public slug, not deleted), narrowed by a search term when given.
     * Unpaginated on purpose - the caller sorts by XP or activity, neither of which is expressible here,
     * then pages the result.
     *
     * @return list<string>
     */
    public function listableIds(?string $search): array;

    /**
     * Most recent activity-feed timestamp per user, for the "recently active" sort. Users with no activity
     * at all are absent from the map.
     *
     * @param list<string> $userIds
     *
     * @return array<string, string>
     */
    public function lastActivityAt(array $userIds): array;

    /**
     * How many members are listable at all (a public slug, not deleted) - the population every community
     * surface counts, and the same base DbalAchievementRarityQuery divides by. Story 30.38 (hub header).
     */
    public function listableMemberCount(): int;
}
