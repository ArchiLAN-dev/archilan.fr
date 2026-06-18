<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\CommunityXp;
use App\Community\Domain\FriendshipRepositoryInterface;
use App\Community\Domain\Level;

/**
 * The /communaute directory (story 30.15): browse/search members, rank by canonical XP, list recently
 * active and the viewer's friends. Composes the lightweight directory query with the shared user cards,
 * XP/level (CommunityXp - single source) and presence; never the full per-profile read.
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
            $rows = $this->enrich($result['ids']);

            return $this->page($rows, $result['total'], $page, $perPage);
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
        $components = $this->directory->xpComponents(null);

        $ranked = [];
        foreach ($components as $userId => $c) {
            $ranked[$userId] = $this->xpOf($c);
        }
        // Highest XP first; stable tiebreak by id so paging is deterministic.
        uksort($ranked, static function (string $a, string $b) use ($ranked): int {
            return $ranked[$b] <=> $ranked[$a] ?: strcmp($a, $b);
        });

        $pageIds = array_slice(array_keys($ranked), $offset, $perPage);

        // Reuse the components already computed for ranking instead of re-querying the page.
        return $this->page($this->enrich($pageIds, $components), count($ranked), $page, $perPage);
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
        $components = $this->directory->xpComponents($friendIds);
        usort($friendIds, function (string $a, string $b) use ($components): int {
            $xa = isset($components[$a]) ? $this->xpOf($components[$a]) : 0;
            $xb = isset($components[$b]) ? $this->xpOf($components[$b]) : 0;

            return $xb <=> $xa ?: strcmp($a, $b);
        });

        $pageIds = array_slice($friendIds, $offset, $perPage);

        return $this->page($this->enrich($pageIds, $components), count($friendIds), $page, $perPage);
    }

    /**
     * Enrich an ordered list of user ids into directory rows (drops ids without a public card).
     *
     * @param list<string>                                                                                                            $userIds
     * @param array<string, array{goalCompletions: int, totalChecksDone: int, runsParticipated: int, achievementsUnlocked: int}>|null $components
     *
     * @return list<array{slug: string, displayName: string|null, avatarUrl: string|null, level: int, xp: int, playing: bool}>
     */
    private function enrich(array $userIds, ?array $components = null): array
    {
        if ([] === $userIds) {
            return [];
        }

        $cards = $this->cards->cards($userIds);
        $components ??= $this->directory->xpComponents($userIds);
        $playing = $this->presence->playing($userIds);

        $rows = [];
        foreach ($userIds as $userId) {
            $card = $cards[$userId] ?? null;
            if (null === $card) {
                continue;
            }
            $xp = isset($components[$userId]) ? $this->xpOf($components[$userId]) : 0;
            $rows[] = [
                'slug' => $card['slug'],
                'displayName' => $card['displayName'],
                'avatarUrl' => $card['avatarUrl'],
                'level' => Level::fromXp($xp)->level,
                'xp' => $xp,
                'playing' => isset($playing[$userId]),
            ];
        }

        return $rows;
    }

    /**
     * @param array{goalCompletions: int, totalChecksDone: int, runsParticipated: int, achievementsUnlocked: int} $c
     */
    private function xpOf(array $c): int
    {
        return CommunityXp::compute($c['goalCompletions'], $c['totalChecksDone'], $c['runsParticipated'], $c['achievementsUnlocked']);
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
