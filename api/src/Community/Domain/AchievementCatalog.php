<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * The code-defined achievement catalog. Deterministic, recomputable, monotonic (a recompute only adds
 * grants, never revokes). Add definitions here; the next recompute backfills them.
 */
final class AchievementCatalog
{
    /**
     * @return list<AchievementDefinition>
     */
    public static function all(): array
    {
        return [
            new AchievementDefinition('first_run', 'Première partie', 'Participer à une partie multivers.', AchievementDefinition::METRIC_RUNS, 1),
            new AchievementDefinition('regular', 'Habitué', 'Participer à 10 parties.', AchievementDefinition::METRIC_RUNS, 10),
            new AchievementDefinition('veteran', 'Vétéran', 'Participer à 50 parties.', AchievementDefinition::METRIC_RUNS, 50),
            new AchievementDefinition('first_goal', 'Premier objectif', 'Atteindre l\'objectif d\'une partie.', AchievementDefinition::METRIC_GOALS, 1),
            new AchievementDefinition('goal_hunter', 'Chasseur d\'objectifs', 'Atteindre 10 objectifs.', AchievementDefinition::METRIC_GOALS, 10),
            new AchievementDefinition('explorer', 'Explorateur', 'Compléter 1 000 checks au total.', AchievementDefinition::METRIC_CHECKS, 1000),
            new AchievementDefinition('collector', 'Collectionneur', 'Recevoir 1 000 items au total.', AchievementDefinition::METRIC_ITEMS, 1000),
            new AchievementDefinition('polyglot', 'Polyglotte', 'Jouer à 5 jeux différents.', AchievementDefinition::METRIC_DISTINCT_GAMES, 5),
            new AchievementDefinition('omnivore', 'Omnivore', 'Jouer à 15 jeux différents.', AchievementDefinition::METRIC_DISTINCT_GAMES, 15),
        ];
    }
}
