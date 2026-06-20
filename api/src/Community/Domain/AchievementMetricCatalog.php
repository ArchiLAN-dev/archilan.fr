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
        ];
    }

    public static function isValidFact(string $fact): bool
    {
        return \array_key_exists($fact, self::facts());
    }
}
