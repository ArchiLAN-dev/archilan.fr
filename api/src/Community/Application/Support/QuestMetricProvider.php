<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Application\Port\AchievementMetricProviderInterface;
use App\Community\Application\Query\QuestAchievementsQueryInterface;
use App\Community\Domain\AchievementMetricCatalog;

/**
 * Weekly quest achievement facts (story 41.17): the quests completed in all, and the longest run of consecutive
 * weeks the member opened the chest (every quest of the week done).
 */
final readonly class QuestMetricProvider implements AchievementMetricProviderInterface
{
    public function __construct(private QuestAchievementsQueryInterface $quests)
    {
    }

    public function metricsFor(string $userId): array
    {
        return [
            AchievementMetricCatalog::FACT_QUESTS_COMPLETED => $this->quests->questsCompleted($userId),
            AchievementMetricCatalog::FACT_QUEST_CHEST_STREAK => self::longestStreak($this->quests->chestWeeks($userId)),
        ];
    }

    /**
     * The longest run of consecutive ISO weeks among the keys (`2026-W41`), across year ends.
     *
     * @param list<string> $weekKeys
     */
    public static function longestStreak(array $weekKeys): int
    {
        $mondays = [];
        foreach (array_unique($weekKeys) as $key) {
            if (1 === preg_match('/^(\d{4})-W(\d{2})$/', $key, $parts)) {
                // Days since the epoch of the week's Monday: consecutive weeks are 7 days apart.
                $monday = new \DateTimeImmutable('@0')->setISODate((int) $parts[1], (int) $parts[2]);
                $mondays[] = intdiv($monday->getTimestamp(), 86400);
            }
        }
        sort($mondays);

        $longest = 0;
        $run = 0;
        $previous = null;
        foreach ($mondays as $day) {
            $run = null !== $previous && 7 === $day - $previous ? $run + 1 : 1;
            $longest = max($longest, $run);
            $previous = $day;
        }

        return $longest;
    }
}
