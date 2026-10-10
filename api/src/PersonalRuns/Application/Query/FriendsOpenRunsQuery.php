<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Query;

use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;

/**
 * « Parties de tes amis » (story 43.14): the draft runs friends opened to the viewer, with a seat left. A run
 * whose owner has no listable card is left out, as on « Mes parties ».
 */
final readonly class FriendsOpenRunsQuery
{
    public function __construct(
        private FriendsOpenRunsQueryInterface $openRuns,
        private CommunityUserDirectoryQueryInterface $directory,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forViewer(string $viewerId): array
    {
        $runs = array_values(array_filter(
            $this->openRuns->openRunsFor($viewerId),
            static fn (array $run): bool => null === $run['seatsWanted'] || $run['joined'] < $run['seatsWanted'],
        ));
        $cards = $this->directory->cards(array_values(array_unique(array_map(static fn (array $run): string => $run['ownerId'], $runs))));

        $rows = [];
        foreach ($runs as $run) {
            $owner = $cards[$run['ownerId']] ?? null;
            if (null === $owner) {
                continue;
            }
            $rows[] = [
                'runId' => $run['runId'],
                'title' => $run['title'],
                'seatsWanted' => $run['seatsWanted'],
                'joined' => $run['joined'],
                'createdAt' => $run['createdAt']->format(\DATE_ATOM),
                'owner' => $owner,
            ];
        }

        return $rows;
    }
}
