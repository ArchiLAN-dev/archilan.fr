<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\AchievementCatalog;
use App\Community\Domain\AchievementGrant;
use App\Community\Domain\AchievementGrantRepositoryInterface;
use App\Community\Domain\AchievementMetrics;
use App\Identity\Application\PlayerHistoryQueryInterface;
use App\Identity\Application\PlayerStatsQueryInterface;

/**
 * Deterministic achievement engine (epic §E.1): derives a user's metrics from the Epic-18 read models,
 * evaluates the code-defined catalog, and persists newly-unlocked grants. Monotonic - it only ever adds
 * grants, never revokes one (a later stat invalidation cannot un-earn an achievement). Idempotent.
 */
final readonly class RecomputeAchievements
{
    public function __construct(
        private PlayerStatsQueryInterface $stats,
        private PlayerHistoryQueryInterface $history,
        private AchievementGrantRepositoryInterface $grants,
    ) {
    }

    /**
     * @return int the number of newly granted achievements
     */
    public function recomputeForUser(string $userId): int
    {
        $metrics = $this->metricsFor($userId);
        $alreadyGranted = array_flip($this->grants->grantedKeys($userId));
        $now = new \DateTimeImmutable();

        $added = 0;
        foreach (AchievementCatalog::all() as $definition) {
            if (isset($alreadyGranted[$definition->key])) {
                continue;
            }
            if ($definition->isUnlockedBy($metrics)) {
                $this->grants->save(AchievementGrant::grant($userId, $definition->key, $now));
                ++$added;
            }
        }

        return $added;
    }

    private function metricsFor(string $userId): AchievementMetrics
    {
        $stats = $this->stats->computeForUser($userId);

        return new AchievementMetrics(
            $stats['runs_participated'],
            $stats['goal_completions'],
            $stats['total_checks_done'],
            $stats['total_items_received'],
            $this->distinctGames($userId),
        );
    }

    private function distinctGames(string $userId): int
    {
        $games = [];
        foreach ($this->history->fetchForUser($userId) as $row) {
            $game = $row['game'] ?? null;
            if (is_string($game) && '' !== $game) {
                $games[$game] = true;
            }
        }

        return count($games);
    }
}
