<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * The initial achievement catalog (story 30.16) — the 9 entries that used to be code-defined, now used to
 * seed the database (migration) and the functional-test fixtures. After seeding, the DB is the source of
 * truth and these are editable from the admin form.
 */
final class DefaultAchievementDefinitions
{
    /**
     * @return list<array{key: string, name: string, description: string, rule: array<string, mixed>}>
     */
    public static function all(): array
    {
        return [
            self::def('first_run', 'Première partie', 'Participer à une partie multivers.', AchievementMetricCatalog::FACT_RUNS, 1),
            self::def('regular', 'Habitué', 'Participer à 10 parties.', AchievementMetricCatalog::FACT_RUNS, 10),
            self::def('veteran', 'Vétéran', 'Participer à 50 parties.', AchievementMetricCatalog::FACT_RUNS, 50),
            self::def('first_goal', 'Premier objectif', 'Atteindre l\'objectif d\'une partie.', AchievementMetricCatalog::FACT_GOALS, 1),
            self::def('goal_hunter', 'Chasseur d\'objectifs', 'Atteindre 10 objectifs.', AchievementMetricCatalog::FACT_GOALS, 10),
            self::def('explorer', 'Explorateur', 'Compléter 1 000 checks au total.', AchievementMetricCatalog::FACT_CHECKS, 1000),
            self::def('collector', 'Collectionneur', 'Recevoir 1 000 items au total.', AchievementMetricCatalog::FACT_ITEMS, 1000),
            self::def('polyglot', 'Polyglotte', 'Jouer à 5 jeux différents.', AchievementMetricCatalog::FACT_DISTINCT_GAMES, 5),
            self::def('omnivore', 'Omnivore', 'Jouer à 15 jeux différents.', AchievementMetricCatalog::FACT_DISTINCT_GAMES, 15),
        ];
    }

    /**
     * @return array{key: string, name: string, description: string, rule: array<string, mixed>}
     */
    private static function def(string $key, string $name, string $description, string $fact, int $threshold): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'description' => $description,
            'rule' => [
                'op' => AchievementRuleGroup::OP_ALL,
                'rules' => [
                    ['fact' => $fact, 'operator' => AchievementOperator::GreaterOrEqual->value, 'value' => $threshold],
                ],
            ],
        ];
    }
}
