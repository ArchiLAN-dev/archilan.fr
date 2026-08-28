<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Community\Domain\Repository\FriendshipRepositoryInterface;
use App\Streaming\Application\Query\ParticipantTwitchLinksQueryInterface;
use App\Streaming\Application\Support\LiveTwitchLogins;
use App\Streaming\Domain\Service\TwitchLinkResolver;

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
        private ParticipantTwitchLinksQueryInterface $twitchLinks,
        private LiveTwitchLogins $liveLogins,
        private FriendshipRepositoryInterface $friendships,
        private CommunityLevelQuery $levels,
    ) {
    }

    /**
     * @return array{
     *     rows: list<array{slug: string, displayName: string|null, avatarUrl: string|null, level: int, xp: int, xpIntoLevel: int, xpForNextLevel: int, playing: bool, liveTwitchLogin: string|null}>,
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

        // Story 30.39 : les membres en direct remontent en tête, à l'intérieur du tri choisi. Le
        // classement porte sur l'ensemble des membres, pas sur la page : remonter après le découpage
        // ne ferait flotter les live qu'à l'intérieur de leur page, et quelqu'un pourrait apparaître
        // deux fois ou disparaître entre deux.
        $liveLoginByUser = $this->liveLoginByUser($candidateIds);
        $sortedIds = self::liveFirst($sortedIds, $liveLoginByUser);

        $pageIds = array_slice($sortedIds, $offset, $perPage);

        return $this->page($this->enrich($pageIds, null, $levels, $liveLoginByUser), count($sortedIds), $page, $perPage);
    }

    /**
     * Le login Twitch de chaque membre actuellement en direct, parmi ceux fournis.
     *
     * Un seul appel groupé, mis en cache et partagé avec la vue des streams d'une partie : le client
     * Twitch découpe déjà par 100, donc passer toute l'association ne coûte pas plus qu'une page.
     *
     * @param list<string> $userIds
     *
     * @return array<string, string> userId => login en direct
     */
    private function liveLoginByUser(array $userIds): array
    {
        $loginByUser = [];
        foreach ($this->twitchLinks->forUserIds($userIds) as $row) {
            $login = TwitchLinkResolver::resolveLogin($row['socialLinks']);
            if (null !== $login) {
                $loginByUser[$row['userId']] = $login;
            }
        }

        if ([] === $loginByUser) {
            return [];
        }

        $live = $this->liveLogins->among(array_values($loginByUser));

        return array_filter($loginByUser, static fn (string $login): bool => array_key_exists($login, $live));
    }

    /**
     * Remonte les membres en direct sans casser l'ordre : le tri choisi continue de s'appliquer à
     * l'intérieur de chaque groupe.
     *
     * @param list<string>          $sortedIds
     * @param array<string, string> $liveLoginByUser
     *
     * @return list<string>
     */
    private static function liveFirst(array $sortedIds, array $liveLoginByUser): array
    {
        if ([] === $liveLoginByUser) {
            return $sortedIds;
        }

        $live = [];
        $rest = [];
        foreach ($sortedIds as $userId) {
            if (isset($liveLoginByUser[$userId])) {
                $live[] = $userId;
            } else {
                $rest[] = $userId;
            }
        }

        return [...$live, ...$rest];
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
     * @param array<string, string>                                                                                                                                                               $liveLoginByUser
     *
     * @return list<array{slug: string, displayName: string|null, avatarUrl: string|null, level: int, xp: int, xpIntoLevel: int, xpForNextLevel: int, playing: bool, liveTwitchLogin: string|null}>
     */
    private function enrich(array $userIds, ?array $cards = null, ?array $levels = null, array $liveLoginByUser = []): array
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
                // Story 30.39 : le login sert de lien vers la chaîne ; null quand le membre ne diffuse pas.
                'liveTwitchLogin' => $liveLoginByUser[$userId] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{slug: string, displayName: string|null, avatarUrl: string|null, level: int, xp: int, xpIntoLevel: int, xpForNextLevel: int, playing: bool, liveTwitchLogin: string|null}> $rows
     *
     * @return array{rows: list<array{slug: string, displayName: string|null, avatarUrl: string|null, level: int, xp: int, xpIntoLevel: int, xpForNextLevel: int, playing: bool, liveTwitchLogin: string|null}>, total: int, page: int, perPage: int}
     */
    private function page(array $rows, int $total, int $page, int $perPage): array
    {
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }
}
