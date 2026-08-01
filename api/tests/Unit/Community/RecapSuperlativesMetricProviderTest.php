<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Query\RecapSuperlativesQueryInterface;
use App\Community\Application\Support\RecapSuperlativesMetricProvider;
use App\Community\Domain\AchievementMetricCatalog;
use PHPUnit\Framework\TestCase;

final class RecapSuperlativesMetricProviderTest extends TestCase
{
    public function testEmitsTotalPlusSparsePerKeyFacts(): void
    {
        $provider = new RecapSuperlativesMetricProvider($this->queryReturning([
            'most_generous' => 2,
            'first_to_goal' => 1,
        ]));

        self::assertSame([
            AchievementMetricCatalog::FACT_SUPERLATIVES => 3,
            AchievementMetricCatalog::SUPERLATIVE_PREFIX.'most_generous' => 2,
            AchievementMetricCatalog::SUPERLATIVE_PREFIX.'first_to_goal' => 1,
        ], $provider->metricsFor('user-1'));
    }

    public function testEmitsOnlyTheZeroTotalWithoutSuperlatives(): void
    {
        $provider = new RecapSuperlativesMetricProvider($this->queryReturning([]));

        self::assertSame([AchievementMetricCatalog::FACT_SUPERLATIVES => 0], $provider->metricsFor('user-1'));
    }

    public function testCatalogExposesTheSuperlativeFacts(): void
    {
        $facts = AchievementMetricCatalog::facts();

        self::assertArrayHasKey(AchievementMetricCatalog::FACT_SUPERLATIVES, $facts);
        foreach (['most_generous', 'biggest_hub', 'first_to_goal', 'longest_road'] as $key) {
            $fact = AchievementMetricCatalog::SUPERLATIVE_PREFIX.$key;
            self::assertArrayHasKey($fact, $facts);
            self::assertTrue(AchievementMetricCatalog::isValidFact($fact));
        }

        // The prefix is a fixed enumeration, not an open family like event_goal: an unknown
        // superlative key is not a valid fact.
        self::assertFalse(AchievementMetricCatalog::isValidFact(AchievementMetricCatalog::SUPERLATIVE_PREFIX.'unknown'));
    }

    /**
     * @param array<string, int> $counts
     */
    private function queryReturning(array $counts): RecapSuperlativesQueryInterface
    {
        return new readonly class($counts) implements RecapSuperlativesQueryInterface {
            /**
             * @param array<string, int> $counts
             */
            public function __construct(private array $counts)
            {
            }

            public function superlativeCountsFor(string $userId): array
            {
                return $this->counts;
            }
        };
    }
}
