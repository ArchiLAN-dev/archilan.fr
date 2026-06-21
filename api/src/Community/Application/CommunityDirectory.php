<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\FriendshipRepositoryInterface;

/**
 * The /communaute directory (story 30.15): browse/search members, rank by canonical XP, list recently
 * active and the viewer's friends. Level/XP come from the shared CommunityLevelQuery - the single source
 * used by the public profile - so a member's level is identical on every surface.
 */
final readonly class CommunityDirectory
{
    public const MODE_TOP = 'top';
    public const MODE_RECENT = 'recent';
    public const MODE_FRIENDS = 'friends';

    private const DEFAULT_PER_PAGE = 24;
    private const MAX_PER_PAGE = 60;

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
     *     rows: list<array{slug: string, displayName: string|null, avatarUrl: string|null, level: int, xp: int, playing: bool}>,
     *     total: int, page: int, perPage: int
     * }
     */
    public function browse(string $mode, ?string $search, ?string $viewerId, int $page, int $perPage): array
    {
        $perPage = $perPage <= 0 ? self::DEFAULT_PER_PAGE : min($perPage, self::MAX_PER_PAGE);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $term = null === $search ? '' : trim($search);

        if ('' !== $term) {
            $result = $this->directory->search($term, $perPage, $offset);

            return $this->page($this->enrich($result['ids']), $result['total'], $page, $perPage);
        }

        return match ($mode) {
            self::MODE_RECENT => $this->recent($page, $perPage, $offset),
            self::MODE_FRIENDS => $this->friends($viewerId, $page, $perPage, $offset),
            default => $this->top($page, $perPage, $offset),
        };
    }

    /**
     * @return array{rows: list<array{slug: string, displayName: string|null, avatarUrl: string|null, level: int, xp: int, playing: bool}>, total: int, page: int, perPage: int}
     */
    private function top(int $page, int $perPage, int $offset): array
    {
        // Level/XP for every user with any activity or achievement, then keep only listable members (those
        // with a public card) so the ranking and totals match the displayed rows.
        $levels = $this->levels->levelForMany(null);
        if ([] === $levels) {
            return $this->page([], 0, $page, $perPage);
        }

        $cards = $this->cards->cards(array_keys($levels));

        $ranked = [];
        foreach ($levels as $userId => $level) {
            if (isset($cards[$userId])) {
                $ranked[$userId] = $level['xp'];
            }
        }
        // Highest XP first; stable tiebreak by id so paging is deterministic.
        uksort($ranked, static fn (string $a, string $b): int => $ranked[$b] <=> $ranked[$a] ?: strcmp($a, $b));

        $pageIds = array_slice(array_keys($ranked), $offset, $perPage);

        return $this->page($this->enrich($pageIds, $cards, $levels), count($ranked), $page, $perPage);
    }

    /**
     * @return array{rows: list<array{slug: string, displayName: string|null, avatarUrl: string|null, level: int, xp: int, playing: bool}>, total: int, page: int, perPage: int}
     */
    private function recent(int $page, int $perPage, int $offset): array
    {
        $result = $this->directory->recentlyActive($perPage, $offset);

        return $this->page($this->enrich($result['ids']), $result['total'], $page, $perPage);
    }

    /**
     * @return array{rows: list<array{slug: string, displayName: string|null, avatarUrl: string|null, level: int, xp: int, playing: bool}>, total: int, page: int, perPage: int}
     */
    private function friends(?string $viewerId, int $page, int $perPage, int $offset): array
    {
        if (null === $viewerId) {
            return $this->page([], 0, $page, $perPage);
        }

        $friendIds = [];
        foreach ($this->friendships->findAccepted($viewerId) as $friendship) {
            $friendIds[] = $friendship->otherParty($viewerId);
        }
        $friendIds = array_values(array_unique($friendIds));

        // Rank friends by XP too, for a consistent ordering.
        $levels = $this->levels->levelForMany($friendIds);
        usort($friendIds, static function (string $a, string $b) use ($levels): int {
            $xa = $levels[$a]['xp'] ?? 0;
            $xb = $levels[$b]['xp'] ?? 0;

            return $xb <=> $xa ?: strcmp($a, $b);
        });

        $pageIds = array_slice($friendIds, $offset, $perPage);

        return $this->page($this->enrich($pageIds, null, $levels), count($friendIds), $page, $perPage);
    }

    /**
     * Enrich an ordered list of user ids into directory rows (drops ids without a public card).
     *
     * @param list<string>                                                                                                                                                                        $userIds
     * @param array<string, array{userId: string, slug: string, displayName: string|null, avatarUrl: string|null}>|null                                                                           $cards
     * @param array<string, array{level: int, xp: int, xpIntoLevel: int, xpForNextLevel: int, runsParticipated: int, goalCompletions: int, totalChecksDone: int, achievementsUnlocked: int}>|null $levels
     *
     * @return list<array{slug: string, displayName: string|null, avatarUrl: string|null, level: int, xp: int, playing: bool}>
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
                'playing' => isset($playing[$userId]),
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{slug: string, displayName: string|null, avatarUrl: string|null, level: int, xp: int, playing: bool}> $rows
     *
     * @return array{rows: list<array{slug: string, displayName: string|null, avatarUrl: string|null, level: int, xp: int, playing: bool}>, total: int, page: int, perPage: int}
     */
    private function page(array $rows, int $total, int $page, int $perPage): array
    {
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }
}
