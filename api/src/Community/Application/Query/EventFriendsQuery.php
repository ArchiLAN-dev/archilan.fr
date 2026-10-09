<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * « N de tes amis participent » (story 43.4): the viewer's friends registered to an event, as cards. A friend
 * without a listable card (banned, suspended, no slug) is left out. No notification when a friend registers:
 * that is the favourites' job (43.11).
 */
final readonly class EventFriendsQuery
{
    public const int MAX_EVENTS = 50;

    public function __construct(
        private EventFriendsQueryInterface $friends,
        private CommunityUserDirectoryQueryInterface $directory,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forEvent(string $viewerId, string $eventId): array
    {
        return $this->forEvents($viewerId, [$eventId])[$eventId] ?? [];
    }

    /**
     * @param list<string> $eventIds
     *
     * @return array<string, list<array<string, mixed>>> keyed by event id, events without a friend left out
     */
    public function forEvents(string $viewerId, array $eventIds): array
    {
        $eventIds = array_slice(array_values(array_unique($eventIds)), 0, self::MAX_EVENTS);
        $byEvent = $this->friends->friendIdsByEvent($viewerId, $eventIds);
        $cards = $this->directory->cards(array_values(array_unique(array_merge([], ...array_values($byEvent)))));

        $result = [];
        foreach ($byEvent as $eventId => $userIds) {
            $friends = [];
            foreach ($userIds as $userId) {
                if (isset($cards[$userId])) {
                    $friends[] = $cards[$userId];
                }
            }
            if ([] !== $friends) {
                $result[$eventId] = $friends;
            }
        }

        return $result;
    }
}
