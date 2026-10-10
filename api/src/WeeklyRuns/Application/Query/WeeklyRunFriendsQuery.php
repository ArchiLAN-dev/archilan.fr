<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Query;

use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Query\FriendCircleQuery;
use App\WeeklyRuns\Application\Support\WeeklyStanding;

/**
 * « Tes amis cette semaine » (story 43.8): the viewer and their friends who took part in a weekly run, with where
 * they are (registered, launched, goal reached) and their time. Each member's best attempt counts: a goal before a
 * launch before a mere registration, the fastest goal first. Ranked by time, the goals first.
 */
final readonly class WeeklyRunFriendsQuery
{
    public function __construct(
        private FriendCircleQuery $friendCircle,
        private WeeklyRunFriendEntriesQueryInterface $entries,
        private CommunityUserDirectoryQueryInterface $directory,
    ) {
    }

    /**
     * @return list<array<string, mixed>> cards with `status`, `completionTimeSeconds` and `isViewer`
     */
    public function forViewer(string $weeklyRunId, string $viewerId): array
    {
        $best = WeeklyStanding::rank($this->entries->entriesOf($weeklyRunId, $this->friendCircle->memberIds($viewerId)));
        $cards = $this->directory->cards(array_keys($best));
        $rows = [];
        foreach ($best as $userId => $standing) {
            $card = $cards[$userId] ?? null;
            if (null !== $card) {
                $rows[] = [...$card, ...$standing, 'isViewer' => $userId === $viewerId];
            }
        }

        return $rows;
    }
}
