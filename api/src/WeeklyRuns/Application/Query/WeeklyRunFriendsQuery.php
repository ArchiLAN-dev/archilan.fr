<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Query;

use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Query\FriendCircleQuery;

/**
 * « Tes amis cette semaine » (story 43.8): the viewer and their friends who took part in a weekly run, with where
 * they are (registered, launched, goal reached) and their time. Each member's best attempt counts: a goal before a
 * launch before a mere registration, the fastest goal first. Ranked by time, the goals first.
 */
final readonly class WeeklyRunFriendsQuery
{
    private const array STATUS_ORDER = ['goal' => 0, 'launched' => 1, 'registered' => 2];

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
        $best = [];
        foreach ($this->entries->entriesOf($weeklyRunId, $this->friendCircle->memberIds($viewerId)) as $entry) {
            $candidate = [
                'status' => null !== $entry['goalReachedAt'] ? 'goal' : (null !== $entry['launchedAt'] ? 'launched' : 'registered'),
                'completionTimeSeconds' => null !== $entry['goalReachedAt'] ? $entry['completionTimeSeconds'] : null,
            ];
            $current = $best[$entry['userId']] ?? null;
            if (null === $current || self::compare($candidate, $current) < 0) {
                $best[$entry['userId']] = $candidate;
            }
        }
        uasort($best, self::compare(...));

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

    /**
     * @param array{status: string, completionTimeSeconds: int|null} $a
     * @param array{status: string, completionTimeSeconds: int|null} $b
     */
    private static function compare(array $a, array $b): int
    {
        return (self::STATUS_ORDER[$a['status']] ?? 3) <=> (self::STATUS_ORDER[$b['status']] ?? 3)
            ?: ($a['completionTimeSeconds'] ?? PHP_INT_MAX) <=> ($b['completionTimeSeconds'] ?? PHP_INT_MAX);
    }
}
