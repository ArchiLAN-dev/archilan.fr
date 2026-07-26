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
    public const string FACT_RUNS = 'runs';
    public const string FACT_GOALS = 'goals';
    public const string FACT_CHECKS = 'checks';
    public const string FACT_ITEMS = 'items';
    public const string FACT_DISTINCT_GAMES = 'distinctGames';
    public const string FACT_EVENTS_WITH_GOAL = 'eventsWithGoal';
    public const string FACT_SUPERLATIVES = 'superlatives';

    // A specific-event fact: `event_goal:{eventId}` = 1 when the player reached a goal in that event.
    // The id part is opaque to the rule engine; the admin layer checks it is a real event (story 30.32).
    public const string EVENT_GOAL_PREFIX = 'event_goal:';

    // Recap-superlative facts: `superlative:{key}` = times the player won that superlative in a public
    // session recap (story 32.4). Unlike event_goal this is a CLOSED enumeration - the keys mirror
    // Sessions' RecapSuperlativesCalculator (duplicated on purpose: Community never imports Sessions),
    // and each key is a concrete facts() entry so the admin form and validation need no special case.
    public const string SUPERLATIVE_PREFIX = 'superlative:';

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
            self::FACT_SUPERLATIVES => 'Superlatifs de récap remportés (total)',
            self::SUPERLATIVE_PREFIX.'most_generous' => 'Superlatif « Le Parrain » (le plus généreux)',
            self::SUPERLATIVE_PREFIX.'biggest_hub' => 'Superlatif « Le Facteur » (le plus grand hub)',
            self::SUPERLATIVE_PREFIX.'first_to_goal' => 'Superlatif « Speedy Gonzales » (premier au but)',
            self::SUPERLATIVE_PREFIX.'longest_road' => 'Superlatif « Le Seigneur des Anneaux » (la plus longue route)',
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
