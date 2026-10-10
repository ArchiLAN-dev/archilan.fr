<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Community\Domain\Repository\BlockRepositoryInterface;
use App\Community\Domain\Repository\FriendshipRepositoryInterface;
use App\Sessions\Application\Query\ViewableRecapsQuery;

/**
 * « Vous avez joué ensemble » (story 43.9), on another member's profile: how many sessions the viewer and they
 * played together, the first and the last, the five latest (linked to their recap when the viewer may open it), and
 * the items exchanged where a feed was kept. Shown to a non-friend too, never across a block.
 */
final readonly class SharedHistoryQuery
{
    public const int LATEST = 5;

    public function __construct(
        private CommunityUserDirectoryQueryInterface $directory,
        private BlockRepositoryInterface $blocks,
        private FriendshipRepositoryInterface $friendships,
        private SharedHistoryQueryInterface $history,
        private ViewableRecapsQuery $viewableRecaps,
    ) {
    }

    /**
     * @return array<string, mixed>|null null when nothing was played together, for oneself, or across a block
     */
    public function between(string $viewerId, string $slug): ?array
    {
        $otherId = $this->directory->userIdForSlug($slug);
        if (null === $otherId || $otherId === $viewerId || $this->blocks->existsEitherWay($viewerId, $otherId)) {
            return null;
        }

        $sessions = $this->history->sessionsBetween($viewerId, $otherId);
        if ([] === $sessions) {
            return null;
        }

        $latest = array_slice($sessions, 0, self::LATEST);
        $recaps = $this->viewableRecaps->forViewer(array_column($latest, 'sessionId'), $viewerId);
        $last = $sessions[0];
        $first = $sessions[\count($sessions) - 1];

        return [
            'userId' => $otherId,
            'isFriend' => $this->friendships->areFriends($viewerId, $otherId),
            'count' => \count($sessions),
            'firstAt' => $first['playedAt'],
            'lastAt' => $last['playedAt'],
            'latest' => array_map(static fn (array $session): array => [
                'sessionId' => $session['sessionId'],
                'kind' => null !== $session['runId'] ? 'run' : 'event',
                'title' => $session['title'],
                'playedAt' => $session['playedAt'],
                'recap' => $recaps[$session['sessionId']] ?? false,
            ], $latest),
            'items' => $this->history->itemsBetween($viewerId, $otherId, array_column($sessions, 'sessionId')),
        ];
    }
}
