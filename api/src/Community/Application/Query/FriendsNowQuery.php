<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Community\Domain\Repository\FriendFavoriteRepositoryInterface;
use App\Community\Domain\Repository\FriendshipRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * « Mes amis en ce moment » (story 43.5): the viewer's friends playing now, with the game, the kind of session and,
 * when the viewer has access, its title and page; then the friends whose last session ended less than a day ago.
 * Both go through the presence read, so a friend's presence visibility (43.6) and blocks apply. The viewer's starred
 * friends (43.11a) come first in each list.
 */
final readonly class FriendsNowQuery
{
    private const string RECENT_WINDOW = '-24 hours';

    public function __construct(
        private FriendshipRepositoryInterface $friendships,
        private CommunityPresenceQueryInterface $presence,
        private FriendSessionContextQueryInterface $sessions,
        private CommunityUserDirectoryQueryInterface $directory,
        private ClockInterface $clock,
        private FriendFavoriteRepositoryInterface $favorites,
    ) {
    }

    /**
     * @return array{hasFriends: bool, playing: list<array<string, mixed>>, recent: list<array<string, mixed>>}
     */
    public function forViewer(string $viewerId): array
    {
        $friendIds = [];
        foreach ($this->friendships->findAccepted($viewerId) as $friendship) {
            $friendIds[] = $friendship->otherParty($viewerId);
        }
        if ([] === $friendIds) {
            return ['hasFriends' => false, 'playing' => [], 'recent' => []];
        }

        $playing = $this->presence->playing($friendIds, $viewerId);
        $recent = array_diff_key(
            $this->presence->recentlyPlayed($friendIds, $viewerId, $this->clock->now()->modify(self::RECENT_WINDOW)),
            $playing,
        );
        uasort($recent, static fn (array $a, array $b): int => strcmp($b['finishedAt'], $a['finishedAt']));

        $cards = $this->directory->cards(array_values(array_unique([...array_keys($playing), ...array_keys($recent)])));
        $contexts = $this->sessions->forViewer(array_values(array_unique(array_column($playing, 'sessionId'))), $viewerId);

        $favoriteIds = $this->favorites->favoriteIds($viewerId);

        $playingRows = [];
        foreach ($playing as $userId => $live) {
            $card = $cards[$userId] ?? null;
            if (null === $card) {
                continue;
            }
            $context = $contexts[$live['sessionId']] ?? null;
            $playingRows[] = [
                ...$card,
                'isFavorite' => isset($favoriteIds[$userId]),
                'game' => $live['game'],
                'slotState' => $live['slotState'],
                'progressPercent' => $live['progressPercent'],
                'kind' => $context['kind'] ?? null,
                'title' => $context['title'] ?? null,
                'eventId' => $context['eventId'] ?? null,
                'runId' => $context['runId'] ?? null,
            ];
        }
        usort($playingRows, static fn (array $a, array $b): int => $b['isFavorite'] <=> $a['isFavorite']
            ?: strcasecmp($a['displayName'] ?? $a['slug'], $b['displayName'] ?? $b['slug']));

        $recentRows = [];
        foreach ($recent as $userId => $last) {
            $card = $cards[$userId] ?? null;
            if (null !== $card) {
                $recentRows[] = [...$card, 'isFavorite' => isset($favoriteIds[$userId]), 'game' => $last['game'], 'finishedAt' => $last['finishedAt']];
            }
        }
        // Stable: the most recent first inside each group.
        $recentRows = [
            ...array_values(array_filter($recentRows, static fn (array $row): bool => $row['isFavorite'])),
            ...array_values(array_filter($recentRows, static fn (array $row): bool => !$row['isFavorite'])),
        ];

        return ['hasFriends' => true, 'playing' => $playingRows, 'recent' => $recentRows];
    }
}
