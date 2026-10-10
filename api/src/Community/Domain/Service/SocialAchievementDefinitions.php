<?php

declare(strict_types=1);

namespace App\Community\Domain\Service;

use App\Community\Domain\AchievementMetricCatalog;
use App\Community\Domain\AchievementOperator;
use App\Community\Domain\AchievementRuleGroup;

/**
 * The social achievements (story 43.16), on the facts of playing with others. Pop-culture names with a factual
 * subtitle, like the others. Seeded (inactive) by migration Version20261010160000, which holds its own copy of this
 * data (story 43.18): changing these definitions changes neither that migration nor the database. Activated by
 * `community:achievements:recompute --notify --activate=...`; once seeded, the database is the source of truth.
 */
final class SocialAchievementDefinitions
{
    /**
     * @return list<array{key: string, name: string, description: string, rule: array<string, mixed>}>
     */
    public static function all(): array
    {
        return [
            self::def('friends_played_5', 'Les Goonies', 'Jouer avec 5 amis différents.', AchievementMetricCatalog::FACT_DISTINCT_FRIENDS_PLAYED_WITH, 5),
            self::def('same_partner_3', 'L\'Arme fatale', 'Terminer 3 parties avec la même personne.', AchievementMetricCatalog::FACT_MAX_FINISHED_WITH_SAME_PERSON, 3),
            self::def('weekly_duel_won', 'Il ne peut en rester qu\'un', 'Gagner un duel hebdo entre amis.', AchievementMetricCatalog::FACT_WEEKLY_DUELS_WON, 1),
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
