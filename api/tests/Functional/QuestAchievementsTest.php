<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\Command\RecomputeAchievements;
use App\Community\Application\Port\AchievementMetricProviderInterface;
use App\Community\Application\Support\CosmeticRewarder;
use App\Community\Application\Support\MetricBagBuilder;
use App\Community\Application\Support\Notifier;
use App\Community\Domain\AchievementMetricCatalog;
use App\Community\Domain\Entity\AchievementDefinition;
use App\Community\Domain\Repository\AchievementDefinitionRepositoryInterface;
use App\Community\Domain\Repository\AchievementGrantRepositoryInterface;
use App\Community\Domain\Service\QuestAchievementDefinitions;
use Symfony\Component\Clock\MockClock;

/**
 * Story 41.20: the weekly quest achievements, as seeded, grant on the quest facts of story 41.17.
 */
final class QuestAchievementsTest extends FunctionalTestCase
{
    public function testTheQuestAchievementsGrantOnTheQuestsAndTheRunsOfChests(): void
    {
        $definitions = self::getContainer()->get(AchievementDefinitionRepositoryInterface::class);
        self::assertInstanceOf(AchievementDefinitionRepositoryInterface::class, $definitions);
        $grants = self::getContainer()->get(AchievementGrantRepositoryInterface::class);
        self::assertInstanceOf(AchievementGrantRepositoryInterface::class, $grants);
        $notifier = self::getContainer()->get(Notifier::class);
        self::assertInstanceOf(Notifier::class, $notifier);

        $now = new \DateTimeImmutable('2026-10-06T10:00:00+00:00');
        foreach (QuestAchievementDefinitions::all() as $position => $definition) {
            $definitions->save(AchievementDefinition::create($definition['key'], $definition['name'], $definition['description'], $definition['rule'], $position + 1, $now));
        }

        // 12 quests done, and the chest opened 4 weeks in a row at best.
        $facts = new readonly class implements AchievementMetricProviderInterface {
            public function metricsFor(string $userId): array
            {
                return ['questsCompleted' => 12, 'questChestStreak' => 4];
            }
        };
        $recompute = new RecomputeAchievements($definitions, $grants, new MetricBagBuilder([$facts]), $notifier, new MockClock(), $this->rewarder());

        self::assertSame(4, $recompute->recomputeForUser('user-1', notify: false));
        $keys = $grants->grantedKeys('user-1');
        sort($keys);
        self::assertSame(['chest_first', 'chest_streak_4', 'quest_12', 'quest_first'], $keys);
    }

    public function testEveryQuestAchievementHasAUniqueKeyAndAKnownFact(): void
    {
        $keys = array_column(QuestAchievementDefinitions::all(), 'key');

        self::assertCount(6, $keys);
        self::assertSame($keys, array_values(array_unique($keys)));
        foreach (QuestAchievementDefinitions::all() as $definition) {
            $rules = $definition['rule']['rules'] ?? null;
            self::assertIsArray($rules);
            self::assertIsArray($rules[0] ?? null);
            self::assertIsString($rules[0]['fact'] ?? null);
            self::assertTrue(AchievementMetricCatalog::isValidFact($rules[0]['fact']), $definition['key']);
        }
    }

    private function rewarder(): CosmeticRewarder
    {
        $rewarder = self::getContainer()->get(CosmeticRewarder::class);
        self::assertInstanceOf(CosmeticRewarder::class, $rewarder);

        return $rewarder;
    }
}
