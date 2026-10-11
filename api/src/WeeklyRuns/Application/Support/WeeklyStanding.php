<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Support;

/**
 * Where members stand in a weekly run (stories 43.8, 43.15): each member's best attempt counts, a goal before a
 * launch before a mere registration, the fastest goal first.
 */
final class WeeklyStanding
{
    private const array STATUS_ORDER = ['goal' => 0, 'launched' => 1, 'registered' => 2];

    /**
     * @param list<array{userId: string, launchedAt: string|null, goalReachedAt: string|null, completionTimeSeconds: int|null}> $entries
     *
     * @return array<string, array{status: string, completionTimeSeconds: int|null}> keyed by user id, ranked
     */
    public static function rank(array $entries): array
    {
        $best = [];
        foreach ($entries as $entry) {
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

        return $best;
    }

    /**
     * @param array{status: string, completionTimeSeconds: int|null} $a
     * @param array{status: string, completionTimeSeconds: int|null} $b
     */
    public static function compare(array $a, array $b): int
    {
        return (self::STATUS_ORDER[$a['status']] ?? 3) <=> (self::STATUS_ORDER[$b['status']] ?? 3)
            ?: ($a['completionTimeSeconds'] ?? PHP_INT_MAX) <=> ($b['completionTimeSeconds'] ?? PHP_INT_MAX);
    }
}
