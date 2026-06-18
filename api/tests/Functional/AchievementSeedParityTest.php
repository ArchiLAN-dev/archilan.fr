<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\AchievementMetricProviderInterface;
use App\Community\Application\MetricBagBuilder;
use App\Community\Application\Notifier;
use App\Community\Application\RecomputeAchievements;
use App\Community\Domain\AchievementDefinition;
use App\Community\Domain\AchievementDefinitionRepositoryInterface;
use App\Community\Domain\AchievementGrantRepositoryInterface;
use App\Community\Domain\DefaultAchievementDefinitions;

/**
 * Proves the DB-seeded definitions reproduce the historical grant outcomes exactly: a recompute reading the
 * real Doctrine repository, fed a fixed MetricBag, grants precisely the achievements whose thresholds are met.
 */
final class AchievementSeedParityTest extends FunctionalTestCase
{
    public function testSeededDefinitionsGrantTheExpectedKeysOffTheDatabase(): void
    {
        $definitions = self::getContainer()->get(AchievementDefinitionRepositoryInterface::class);
        self::assertInstanceOf(AchievementDefinitionRepositoryInterface::class, $definitions);
        $grants = self::getContainer()->get(AchievementGrantRepositoryInterface::class);
        self::assertInstanceOf(AchievementGrantRepositoryInterface::class, $grants);
        $notifier = self::getContainer()->get(Notifier::class);
        self::assertInstanceOf(Notifier::class, $notifier);

        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $position = 1;
        foreach (DefaultAchievementDefinitions::all() as $def) {
            $definitions->save(AchievementDefinition::create($def['key'], $def['name'], $def['description'], $def['rule'], $position, $now));
            ++$position;
        }

        // Metrics: runs=10, goals=10, checks=1000, items=1000, distinctGames=5.
        $builder = new MetricBagBuilder([$this->fixedProvider([
            'runs' => 10, 'goals' => 10, 'checks' => 1000, 'items' => 1000, 'distinctGames' => 5,
        ])]);
        $recompute = new RecomputeAchievements($definitions, $grants, $builder, $notifier);

        $added = $recompute->recomputeForUser('user-1', notify: false);

        $keys = $grants->grantedKeys('user-1');
        sort($keys);
        self::assertSame(
            ['collector', 'explorer', 'first_goal', 'first_run', 'goal_hunter', 'polyglot', 'regular'],
            $keys,
        );
        self::assertSame(7, $added);

        // Monotonic + idempotent: a second pass off the same DB adds nothing.
        self::assertSame(0, $recompute->recomputeForUser('user-1', notify: false));
    }

    /**
     * @param array<string, int> $facts
     */
    private function fixedProvider(array $facts): AchievementMetricProviderInterface
    {
        return new class($facts) implements AchievementMetricProviderInterface {
            /** @param array<string, int> $facts */
            public function __construct(private array $facts)
            {
            }

            public function metricsFor(string $userId): array
            {
                return $this->facts;
            }
        };
    }
}
