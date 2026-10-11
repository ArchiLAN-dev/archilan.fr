<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Query;

use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Query\FriendCircleQuery;

/**
 * « Parties qui cherchent des joueurs » (story 43.17): the runs listed for every member with a seat left, the latest
 * first, each with its games and the viewer's friends already in it. A run whose owner has no listable card (banned,
 * suspended) is left out.
 */
final readonly class OpenRunListingsQuery
{
    public function __construct(
        private RunListingsQueryInterface $listings,
        private FriendCircleQuery $friendCircle,
        private CommunityUserDirectoryQueryInterface $directory,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forViewer(string $viewerId): array
    {
        $listings = array_values(array_filter(
            $this->listings->listingsFor($viewerId),
            static fn (array $listing): bool => null === $listing['seatsWanted'] || \count($listing['participantIds']) < $listing['seatsWanted'],
        ));
        if ([] === $listings) {
            return [];
        }

        $friends = array_flip($this->friendCircle->memberIds($viewerId));
        $userIds = [];
        $gameIds = [];
        foreach ($listings as $listing) {
            $userIds[] = $listing['ownerId'];
            foreach ($listing['participantIds'] as $participantId) {
                if (isset($friends[$participantId])) {
                    $userIds[] = $participantId;
                }
            }
            array_push($gameIds, ...$listing['gameIds']);
        }
        $cards = $this->directory->cards(array_values(array_unique($userIds)));
        $games = $this->listings->gameNames(array_values(array_unique($gameIds)));

        $rows = [];
        foreach ($listings as $listing) {
            $owner = $cards[$listing['ownerId']] ?? null;
            if (null === $owner) {
                continue;
            }
            $friendsIn = [];
            foreach ($listing['participantIds'] as $participantId) {
                $card = isset($friends[$participantId]) ? ($cards[$participantId] ?? null) : null;
                if (null !== $card) {
                    $friendsIn[] = $card;
                }
            }
            $rows[] = [
                'runId' => $listing['runId'],
                'title' => $listing['title'],
                'pitch' => $listing['pitch'],
                'plannedFor' => $listing['plannedFor']?->format(\DATE_ATOM),
                'listedAt' => $listing['listedAt']->format(\DATE_ATOM),
                'seatsWanted' => $listing['seatsWanted'],
                'joined' => \count($listing['participantIds']),
                'games' => array_values(array_filter(array_map(static fn (string $id): ?string => $games[$id] ?? null, $listing['gameIds']), is_string(...))),
                'owner' => $owner,
                'isOwnerFriend' => isset($friends[$listing['ownerId']]),
                'friendsIn' => $friendsIn,
            ];
        }

        return $rows;
    }
}
