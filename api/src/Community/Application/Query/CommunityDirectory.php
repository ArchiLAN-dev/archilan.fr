<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Community\Domain\Repository\FriendshipRepositoryInterface;

/**
 * The /joueurs directory (story 30.15, restructured by 30.38): browse the members, with a search, a sort
 * and a friends filter that **compose**.
 *
 * The original shape was three exclusive tabs (top / recently active / friends) plus a search that
 * silently replaced whichever tab was active - so "search among my friends" was not expressible, and a
 * term typed under "Mes amis" quietly returned strangers. The three are now orthogonal: the search
 * narrows the candidate set, the friends filter narrows it further, and the sort orders what is left.
 *
 * Sorting happens here rather than in SQL because neither key lives in the user table: XP comes from the
 * shared CommunityLevelQuery (the single source the public profile uses, so a member's level is identical
 * on every surface) and activity from the feed. The candidate set is the listable membership, which is
 * the population the hub counts - small enough to order in PHP, exactly as the old "top" ranking already
 * did.
 */
final readonly class CommunityDirectory
{
    public const string SORT_XP = 'xp';
    public const string SORT_RECENT = 'recent';

    private const int DEFAULT_PER_PAGE = 24;
    private const int MAX_PER_PAGE = 60;

    public function __construct(
        private CommunityDirectoryQueryInterface $directory,
        private CommunityUserDirectoryQueryInterface $cards,
        private CommunityPresenceQueryInterface $presence,
        private FriendshipRepositoryInterface $friendships,
        private CommunityLevelQuery $levels,
    ) {
    }

    /**
     * @return array{
     *     rows: list<array{slug: string, displayName: string|null, avatarUrl: string|null, level: int, xp: int, xpIntoLevel: int, xpForNextLevel: int, playing: bool}>,
     *     total: int, page: int, perPage: int
     * }
     */
    public function browse(
        string $sort,
        ?string $search,
        bool $friendsOnly,
        ?string $viewerId,
        int $page,
        int $perPage,
    ): array {
        $perPage = $perPage <= 0 ? self::DEFAULT_PER_PAGE : min($perPage, self::MAX_PER_PAGE);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $term = null === $search ? '' : trim($search);
        $candidateIds = $this->directory->listableIds('' === $term ? null : $term);

        if ($friendsOnly) {
            // Anonymous + "friends only" is an empty set, not the whole directory: silently widening the
            // filter would show strangers to someone who asked for their friends.
            $candidateIds = null === $viewerId
                ? []
                : array_values(array_intersect($candidateIds, $this->friendIds($viewerId)));
        }

        if ([] === $candidateIds) {
            return $this->page([], 0, $page, $perPage);
        }

        $levels = $this->levels->levelForMany($candidateIds);
        $sortedIds = self::SORT_RECENT === $sort
            ? $this->sortByActivity($candidateIds)
            : $this->sortByXp($candidateIds, $levels);

        $pageIds = array_slice($sortedIds, $offset, $perPage);

        return $this->page($this->enrich($pageIds, null, $levels), count($sortedIds), $page, $perPage);
    }

    /**
     * @return list<string>
     */
    private function friendIds(string $viewerId): array
    {
        $friendIds = [];
        foreach ($this->friendships->findAccepted($viewerId) as $friendship) {
            $friendIds[] = $friendship->otherParty($viewerId);
        }

        return array_values(array_unique($friendIds));
    }

    /**
     * Highest XP first. A member with no XP at all sorts last rather than being dropped: this is the
     * members directory, not the leaderboard - that one lives on /communaute and has its own query.
     *
     * @param list<string>                                                                                                                                                                   $userIds
     * @param array<string, array{level: int, xp: int, xpIntoLevel: int, xpForNextLevel: int, runsParticipated: int, goalCompletions: int, totalChecksDone: int, achievementsUnlocked: int}> $levels
     *
     * @return list<string>
     */
    private function sortByXp(array $userIds, array $levels): array
    {
        // Stable tiebreak by id so paging is deterministic.
        usort($userIds, static function (string $a, string $b) use ($levels): int {
            $xa = $levels[$a]['xp'] ?? 0;
            $xb = $levels[$b]['xp'] ?? 0;

            return $xb <=> $xa ?: strcmp($a, $b);
        });

        return $userIds;
    }

    /**
     * Most recently active first; members with no activity at all come last.
     *
     * @param list<string> $userIds
     *
     * @return list<string>
     */
    private function sortByActivity(array $userIds): array
    {
        $lastAt = $this->directory->lastActivityAt($userIds);

        usort($userIds, static function (string $a, string $b) use ($lastAt): int {
            $ta = $lastAt[$a] ?? '';
            $tb = $lastAt[$b] ?? '';

            return $tb <=> $ta ?: strcmp($a, $b);
        });

        return $userIds;
    }

    /**
     * Enrich an ordered list of user ids into directory rows (drops ids without a public card).
     *
     * @param list<string>                                                                                                                                                                        $userIds
     * @param array<string, array{userId: string, slug: string, displayName: string|null, avatarUrl: string|null}>|null                                                                           $cards
     * @param array<string, array{level: int, xp: int, xpIntoLevel: int, xpForNextLevel: int, runsParticipated: int, goalCompletions: int, totalChecksDone: int, achievementsUnlocked: int}>|null $levels
     *
     * @return list<array{slug: string, displayName: string|null, avatarUrl: string|null, level: int, xp: int, xpIntoLevel: int, xpForNextLevel: int, playing: bool}>
     */
    private function enrich(array $userIds, ?array $cards = null, ?array $levels = null): array
    {
        if ([] === $userIds) {
            return [];
        }

        $cards ??= $this->cards->cards($userIds);
        $levels ??= $this->levels->levelForMany($userIds);
        $playing = $this->presence->playing($userIds);

        $rows = [];
        foreach ($userIds as $userId) {
            $card = $cards[$userId] ?? null;
            if (null === $card) {
                continue;
            }
            $level = $levels[$userId] ?? null;
            $rows[] = [
                'slug' => $card['slug'],
                'displayName' => $card['displayName'],
                'avatarUrl' => $card['avatarUrl'],
                'level' => null !== $level ? $level['level'] : 0,
                'xp' => null !== $level ? $level['xp'] : 0,
                // Progress within the current level - already computed by CommunityLevelQuery and until
                // story 30.38 discarded here, which left the cards unable to draw an XP bar.
                'xpIntoLevel' => null !== $level ? $level['xpIntoLevel'] : 0,
                'xpForNextLevel' => null !== $level ? $level['xpForNextLevel'] : 0,
                'playing' => isset($playing[$userId]),
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{slug: string, displayName: string|null, avatarUrl: string|null, level: int, xp: int, xpIntoLevel: int, xpForNextLevel: int, playing: bool}> $rows
     *
     * @return array{rows: list<array{slug: string, displayName: string|null, avatarUrl: string|null, level: int, xp: int, xpIntoLevel: int, xpForNextLevel: int, playing: bool}>, total: int, page: int, perPage: int}
     */
    private function page(array $rows, int $total, int $page, int $perPage): array
    {
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }
}
