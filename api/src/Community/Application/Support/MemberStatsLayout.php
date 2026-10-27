<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Domain\AchievementMetricCatalog;

/**
 * How the stats page groups a member's facts (story 30.55).
 *
 * Kept out of AchievementMetricCatalog, which stays the catalogue the rules read. A fact of the catalogue that no
 * group names lands in "Autres", so a fact added later for an achievement shows without touching the page. The
 * play-time facts are left out: they are rounded down for the rules, and the page reads the time in seconds.
 */
final class MemberStatsLayout
{
    public const string OTHER_GROUP = 'other';

    /** @var list<string> */
    public const array PLAY_TIME_FACTS = [
        AchievementMetricCatalog::FACT_PLAYED_HOURS,
        AchievementMetricCatalog::FACT_LONGEST_PLAY_SPAN_MINUTES,
        AchievementMetricCatalog::FACT_MAX_PLAYED_HOURS_IN_SESSION,
    ];

    /**
     * @return list<array{key: string, label: string, facts: list<string>}>
     */
    public static function groups(): array
    {
        return [
            ['key' => 'games', 'label' => 'Parties', 'facts' => [
                AchievementMetricCatalog::FACT_RUNS,
                AchievementMetricCatalog::FACT_GOALS,
                AchievementMetricCatalog::FACT_EVENTS_WITH_GOAL,
                AchievementMetricCatalog::FACT_DISTINCT_GAMES,
            ]],
            ['key' => 'progress', 'label' => 'Progression', 'facts' => [
                AchievementMetricCatalog::FACT_CHECKS,
                AchievementMetricCatalog::FACT_ITEMS,
                AchievementMetricCatalog::FACT_ITEMS_FROM_OTHERS,
            ]],
            ['key' => 'deathlink', 'label' => 'DeathLink', 'facts' => [
                AchievementMetricCatalog::FACT_DEATH_LINK_DEATHS,
                AchievementMetricCatalog::FACT_DEATH_LINK_VICTIMS,
                AchievementMetricCatalog::FACT_MAX_DEATH_LINK_DEATHS_IN_SESSION,
            ]],
            ['key' => 'social', 'label' => 'Social', 'facts' => [
                AchievementMetricCatalog::FACT_DISTINCT_COPLAYERS,
                AchievementMetricCatalog::FACT_DISTINCT_FRIENDS_PLAYED_WITH,
                AchievementMetricCatalog::FACT_MAX_FINISHED_WITH_SAME_PERSON,
                AchievementMetricCatalog::FACT_WEEKLY_DUELS_WON,
            ]],
            ['key' => 'weekly', 'label' => 'Hebdo', 'facts' => [
                AchievementMetricCatalog::FACT_QUESTS_COMPLETED,
                AchievementMetricCatalog::FACT_QUEST_CHEST_STREAK,
            ]],
            ['key' => 'recaps', 'label' => 'Recaps', 'facts' => [
                AchievementMetricCatalog::FACT_SUPERLATIVES,
            ]],
        ];
    }

    /**
     * Facts of the catalogue no group names, in catalogue order (per-event and per-superlative facts aside).
     *
     * @return list<string>
     */
    public static function otherFacts(): array
    {
        $named = self::PLAY_TIME_FACTS;
        foreach (self::groups() as $group) {
            $named = [...$named, ...$group['facts']];
        }

        $others = [];
        foreach (array_keys(AchievementMetricCatalog::facts()) as $fact) {
            if (\in_array($fact, $named, true) || str_starts_with($fact, AchievementMetricCatalog::SUPERLATIVE_PREFIX)
                || str_starts_with($fact, AchievementMetricCatalog::EVENT_GOAL_PREFIX)) {
                continue;
            }
            $others[] = $fact;
        }

        return $others;
    }

    /**
     * The per-superlative facts (`superlative:{key}`), in catalogue order.
     *
     * @return list<string>
     */
    public static function superlativeFacts(): array
    {
        return array_values(array_filter(
            array_keys(AchievementMetricCatalog::facts()),
            static fn (string $fact): bool => str_starts_with($fact, AchievementMetricCatalog::SUPERLATIVE_PREFIX),
        ));
    }
}
