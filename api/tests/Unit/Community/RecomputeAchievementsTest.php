<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\MetricBagBuilder;
use App\Community\Application\Notifier;
use App\Community\Application\RecomputeAchievements;
use App\Community\Application\StatsMetricProvider;
use App\Community\Domain\AchievementDefinition;
use App\Community\Domain\AchievementDefinitionRepositoryInterface;
use App\Community\Domain\AchievementGrant;
use App\Community\Domain\AchievementGrantRepositoryInterface;
use App\Community\Domain\DefaultAchievementDefinitions;
use App\Identity\Application\PlayerHistoryQueryInterface;
use App\Identity\Application\PlayerStatsQueryInterface;
use PHPUnit\Framework\TestCase;

final class RecomputeAchievementsTest extends TestCase
{
    public function testDefaultCatalogKeysAreUnique(): void
    {
        $keys = array_map(static fn (array $d): string => $d['key'], DefaultAchievementDefinitions::all());

        self::assertSame(array_values(array_unique($keys)), $keys);
        self::assertNotEmpty($keys);
    }

    public function testGrantsOnlyUnlockedAchievementsAndIsMonotonic(): void
    {
        $service = $this->serviceFor(
            ['runs_participated' => 1, 'goal_completions' => 0, 'total_checks_done' => 0, 'total_items_received' => 0],
            [['game' => 'A'], ['game' => 'B'], ['game' => 'A']],
            $repo = $this->inMemoryGrantRepo(),
        );

        // runs=1 unlocks first_run; 2 distinct games does not reach polyglot(5).
        self::assertSame(1, $service->recomputeForUser('u1'));
        self::assertSame(['first_run'], $repo->grantedKeys('u1'));

        // Idempotent: a second pass adds nothing.
        self::assertSame(0, $service->recomputeForUser('u1'));
        self::assertSame(['first_run'], $repo->grantedKeys('u1'));
    }

    public function testHigherMetricsUnlockMore(): void
    {
        $service = $this->serviceFor(
            ['runs_participated' => 10, 'goal_completions' => 10, 'total_checks_done' => 1000, 'total_items_received' => 1000],
            [['game' => 'A'], ['game' => 'B'], ['game' => 'C'], ['game' => 'D'], ['game' => 'E']],
            $repo = $this->inMemoryGrantRepo(),
        );

        $added = $service->recomputeForUser('u2');
        $keys = $repo->grantedKeys('u2');

        self::assertSame(7, $added);
        self::assertContains('first_run', $keys);
        self::assertContains('regular', $keys);
        self::assertContains('goal_hunter', $keys);
        self::assertContains('explorer', $keys);
        self::assertContains('polyglot', $keys);
        self::assertNotContains('veteran', $keys);
        self::assertNotContains('omnivore', $keys);
    }

    public function testInactiveDefinitionsAreNeverGranted(): void
    {
        $stats = $this->createStub(PlayerStatsQueryInterface::class);
        $stats->method('computeForUser')->willReturn([
            'runs_participated' => 50, 'goal_completions' => 0, 'total_checks_done' => 0, 'total_items_received' => 0,
        ]);
        $history = $this->createStub(PlayerHistoryQueryInterface::class);
        $history->method('fetchForUser')->willReturn([]);

        $builder = new MetricBagBuilder([new StatsMetricProvider($stats, $history)]);
        $definitions = $this->definitionsRepo(deactivate: ['veteran']);
        $grants = $this->inMemoryGrantRepo();
        $service = new RecomputeAchievements($definitions, $grants, $builder, $this->nullNotifier());

        $service->recomputeForUser('u3');

        // runs=50 would reach veteran, but it is inactive → never granted; first_run/regular still do.
        self::assertContains('regular', $grants->grantedKeys('u3'));
        self::assertNotContains('veteran', $grants->grantedKeys('u3'));
    }

    /**
     * @param array<string, int>         $stats
     * @param list<array<string, mixed>> $history
     */
    private function serviceFor(array $stats, array $history, AchievementGrantRepositoryInterface $grants): RecomputeAchievements
    {
        $statsStub = $this->createStub(PlayerStatsQueryInterface::class);
        $statsStub->method('computeForUser')->willReturn($stats);
        $historyStub = $this->createStub(PlayerHistoryQueryInterface::class);
        $historyStub->method('fetchForUser')->willReturn($history);

        $builder = new MetricBagBuilder([new StatsMetricProvider($statsStub, $historyStub)]);

        return new RecomputeAchievements($this->definitionsRepo(), $grants, $builder, $this->nullNotifier());
    }

    /**
     * @param list<string> $deactivate keys to mark inactive
     */
    private function definitionsRepo(array $deactivate = []): AchievementDefinitionRepositoryInterface
    {
        $now = new \DateTimeImmutable();
        $defs = [];
        $position = 1;
        foreach (DefaultAchievementDefinitions::all() as $raw) {
            $def = AchievementDefinition::create($raw['key'], $raw['name'], $raw['description'], $raw['rule'], $position, $now);
            if (\in_array($raw['key'], $deactivate, true)) {
                $def->setActive(false, $now);
            }
            $defs[] = $def;
            ++$position;
        }

        return new class($defs) implements AchievementDefinitionRepositoryInterface {
            /** @param list<AchievementDefinition> $defs */
            public function __construct(private array $defs)
            {
            }

            public function allActive(): array
            {
                return array_values(array_filter($this->defs, static fn (AchievementDefinition $d): bool => $d->isActive()));
            }

            public function all(): array
            {
                return $this->defs;
            }

            public function findById(string $id): ?AchievementDefinition
            {
                foreach ($this->defs as $d) {
                    if ($d->getId() === $id) {
                        return $d;
                    }
                }

                return null;
            }

            public function existsByKey(string $key): bool
            {
                foreach ($this->defs as $d) {
                    if ($d->getKey() === $key) {
                        return true;
                    }
                }

                return false;
            }

            public function maxPosition(): int
            {
                $max = -1;
                foreach ($this->defs as $d) {
                    $max = max($max, $d->getPosition());
                }

                return $max;
            }

            public function save(AchievementDefinition $definition): void
            {
                $this->defs[] = $definition;
            }

            public function flush(): void
            {
            }
        };
    }

    private function nullNotifier(): Notifier
    {
        return new class implements Notifier {
            public function notify(string $recipientId, string $type, array $payload): void
            {
            }
        };
    }

    private function inMemoryGrantRepo(): AchievementGrantRepositoryInterface
    {
        return new class implements AchievementGrantRepositoryInterface {
            /** @var list<AchievementGrant> */
            private array $stored = [];

            public function grantedKeys(string $userId): array
            {
                return array_map(
                    static fn (AchievementGrant $g): string => $g->getAchievementKey(),
                    $this->findByUser($userId),
                );
            }

            public function findByUser(string $userId): array
            {
                return array_values(array_filter(
                    $this->stored,
                    static fn (AchievementGrant $g): bool => $g->getUserId() === $userId,
                ));
            }

            public function countByUsers(?array $userIds): array
            {
                $counts = [];
                foreach ($this->stored as $grant) {
                    if (null !== $userIds && !in_array($grant->getUserId(), $userIds, true)) {
                        continue;
                    }
                    $counts[$grant->getUserId()] = ($counts[$grant->getUserId()] ?? 0) + 1;
                }

                return $counts;
            }

            public function save(AchievementGrant $grant): void
            {
                $this->stored[] = $grant;
            }

            public function ownerOf(string $grantId): ?string
            {
                foreach ($this->stored as $grant) {
                    if ($grant->getId() === $grantId) {
                        return $grant->getUserId();
                    }
                }

                return null;
            }
        };
    }
}
