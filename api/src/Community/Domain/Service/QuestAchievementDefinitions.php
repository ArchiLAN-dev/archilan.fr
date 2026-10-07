<?php

declare(strict_types=1);

namespace App\Community\Domain\Service;

use App\Community\Domain\AchievementMetricCatalog;
use App\Community\Domain\AchievementOperator;
use App\Community\Domain\AchievementRuleGroup;

/**
 * The weekly quest achievements (story 41.20), on the facts of story 41.17: quests completed, and the longest run
 * of weeks with the chest. Pop-culture names with a factual subtitle, like the event ones. Seeded by migration
 * Version20261006120000, which imports this class: keep its name and namespace. Once seeded, the database is the
 * source of truth and the admin form edits them.
 */
final class QuestAchievementDefinitions
{
    /**
     * @return list<array{key: string, name: string, description: string, rule: array<string, mixed>}>
     */
    public static function all(): array
    {
        return [
            self::def('quest_first', 'Un petit pas pour l\'homme', 'Réussir une première quête hebdo.', AchievementMetricCatalog::FACT_QUESTS_COMPLETED, 1),
            self::def('quest_12', 'Les 12 travaux', 'Réussir 12 quêtes hebdo.', AchievementMetricCatalog::FACT_QUESTS_COMPLETED, 12),
            self::def('quest_50', 'Sacré Graal !', 'Réussir 50 quêtes hebdo.', AchievementMetricCatalog::FACT_QUESTS_COMPLETED, 50),
            self::def('chest_first', 'Sésame, ouvre-toi', 'Ouvrir un coffre de la semaine (toutes les quêtes faites).', AchievementMetricCatalog::FACT_QUEST_CHEST_STREAK, 1),
            self::def('chest_streak_4', 'Un jour sans fin', 'Ouvrir le coffre de la semaine 4 semaines d\'affilée.', AchievementMetricCatalog::FACT_QUEST_CHEST_STREAK, 4),
            self::def('chest_streak_10', 'Run, Forrest, run !', 'Ouvrir le coffre de la semaine 10 semaines d\'affilée.', AchievementMetricCatalog::FACT_QUEST_CHEST_STREAK, 10),
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
