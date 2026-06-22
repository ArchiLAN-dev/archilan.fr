<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * The facts an achievement rule may reference (story 30.16). The set is code-defined (each derives from an
 * existing read model via a metric provider); the admin form composes rules over these keys. Adding a new
 * fact = add an entry here + a provider that supplies it.
 */
final class AchievementMetricCatalog
{
    public const FACT_RUNS = 'runs';
    public const FACT_GOALS = 'goals';
    public const FACT_CHECKS = 'checks';
    public const FACT_ITEMS = 'items';
    public const FACT_DISTINCT_GAMES = 'distinctGames';
    public const FACT_EVENTS_WITH_GOAL = 'eventsWithGoal';

    // A specific-event fact: `event_goal:{eventId}` = 1 when the player reached a goal in that event.
    // The id part is opaque to the rule engine; the admin layer checks it is a real event (story 30.32).
    public const EVENT_GOAL_PREFIX = 'event_goal:';

    /**
     * @return array<string, string> fact key => human label
     */
    public static function facts(): array
    {
        return [
            self::FACT_RUNS => 'Parties jouées',
            self::FACT_GOALS => 'Objectifs atteints',
            self::FACT_CHECKS => 'Checks complétés (total)',
            self::FACT_ITEMS => 'Items reçus (total)',
            self::FACT_DISTINCT_GAMES => 'Jeux différents joués',
            self::FACT_EVENTS_WITH_GOAL => 'Événements avec objectif atteint',
        ];
    }

    public static function isValidFact(string $fact): bool
    {
        return \array_key_exists($fact, self::facts()) || self::isEventGoalFact($fact);
    }

    public static function isEventGoalFact(string $fact): bool
    {
        return str_starts_with($fact, self::EVENT_GOAL_PREFIX) && \strlen($fact) > \strlen(self::EVENT_GOAL_PREFIX);
    }

    public static function eventIdFromFact(string $fact): ?string
    {
        return self::isEventGoalFact($fact) ? substr($fact, \strlen(self::EVENT_GOAL_PREFIX)) : null;
    }
}
