<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\RecomputeAchievements;
use App\Community\Domain\AchievementCatalog;
use App\Community\Domain\AchievementGrant;
use App\Community\Domain\AchievementGrantRepositoryInterface;
use App\Identity\Application\PlayerHistoryQueryInterface;
use App\Identity\Application\PlayerStatsQueryInterface;
use PHPUnit\Framework\TestCase;

final class RecomputeAchievementsTest extends TestCase
{
    public function testCatalogKeysAreUnique(): void
    {
        $keys = array_map(static fn ($d): string => $d->key, AchievementCatalog::all());

        self::assertSame(array_values(array_unique($keys)), $keys);
        self::assertNotEmpty($keys);
    }

    public function testGrantsOnlyUnlockedAchievementsAndIsMonotonic(): void
    {
        $stats = $this->createStub(PlayerStatsQueryInterface::class);
        $stats->method('computeForUser')->willReturn([
            'runs_participated' => 1,
            'goal_completions' => 0,
            'total_checks_done' => 0,
            'total_items_received' => 0,
        ]);
        $history = $this->createStub(PlayerHistoryQueryInterface::class);
        $history->method('fetchForUser')->willReturn([['game' => 'A'], ['game' => 'B'], ['game' => 'A']]);

        $repo = $this->inMemoryRepo();
        $service = new RecomputeAchievements($stats, $history, $repo);

        // runs=1 unlocks first_run; 2 distinct games does not reach polyglot(5).
        self::assertSame(1, $service->recomputeForUser('u1'));
        self::assertSame(['first_run'], $repo->grantedKeys('u1'));

        // Idempotent: a second pass adds nothing.
        self::assertSame(0, $service->recomputeForUser('u1'));
        self::assertSame(['first_run'], $repo->grantedKeys('u1'));
    }

    public function testHigherMetricsUnlockMore(): void
    {
        $stats = $this->createStub(PlayerStatsQueryInterface::class);
        $stats->method('computeForUser')->willReturn([
            'runs_participated' => 10,
            'goal_completions' => 10,
            'total_checks_done' => 1000,
            'total_items_received' => 1000,
        ]);
        $history = $this->createStub(PlayerHistoryQueryInterface::class);
        $history->method('fetchForUser')->willReturn([
            ['game' => 'A'], ['game' => 'B'], ['game' => 'C'], ['game' => 'D'], ['game' => 'E'],
        ]);

        $repo = $this->inMemoryRepo();
        $added = (new RecomputeAchievements($stats, $history, $repo))->recomputeForUser('u2');

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

    private function inMemoryRepo(): AchievementGrantRepositoryInterface
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
