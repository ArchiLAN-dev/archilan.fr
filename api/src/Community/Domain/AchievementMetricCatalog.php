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
    // Story 41.17: the weekly quests - completed in all, and the longest run of weeks with the chest.
    public const string FACT_QUESTS_COMPLETED = 'questsCompleted';
    public const string FACT_QUEST_CHEST_STREAK = 'questChestStreak';
    // Story 30.49: items found by another player for one of the member's slots.
    public const string FACT_ITEMS_FROM_OTHERS = 'itemsFromOthers';
    // Story 43.16: playing with others - who, which of them friends, how often with the same one, duels won.
    public const string FACT_DISTINCT_COPLAYERS = 'distinctCoplayers';
    public const string FACT_DISTINCT_FRIENDS_PLAYED_WITH = 'distinctFriendsPlayedWith';
    public const string FACT_MAX_FINISHED_WITH_SAME_PERSON = 'maxFinishedWithSamePerson';
    public const string FACT_WEEKLY_DUELS_WON = 'weeklyDuelsWon';

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
            self::FACT_ITEMS_FROM_OTHERS => 'Items reçus d\'autres joueurs (hors release et collect)',
            self::FACT_DISTINCT_GAMES => 'Jeux différents joués',
            self::FACT_EVENTS_WITH_GOAL => 'Événements avec objectif atteint',
            self::FACT_SUPERLATIVES => 'Superlatifs de récap remportés (total)',
            self::FACT_QUESTS_COMPLETED => 'Quêtes hebdo réussies (total)',
            self::FACT_QUEST_CHEST_STREAK => 'Plus longue série de semaines avec le coffre',
            self::FACT_DISTINCT_COPLAYERS => 'Joueurs différents avec qui jouer (runs perso et événements)',
            self::FACT_DISTINCT_FRIENDS_PLAYED_WITH => 'Amis différents avec qui jouer (runs perso et événements)',
            self::FACT_MAX_FINISHED_WITH_SAME_PERSON => 'Parties terminées avec une même personne (record)',
            self::FACT_WEEKLY_DUELS_WON => 'Duels hebdo gagnés',
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
