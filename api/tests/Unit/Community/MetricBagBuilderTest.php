<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\AchievementMetricProviderInterface;
use App\Community\Application\MetricBagBuilder;
use PHPUnit\Framework\TestCase;

final class MetricBagBuilderTest extends TestCase
{
    public function testComposesFactsFromEveryProvider(): void
    {
        $builder = new MetricBagBuilder([
            $this->provider(['runs' => 3, 'goals' => 1]),
            $this->provider(['friendsCount' => 7]),
        ]);

        $bag = $builder->build('u1');

        self::assertSame(3, $bag->get('runs'));
        self::assertSame(1, $bag->get('goals'));
        self::assertSame(7, $bag->get('friendsCount'));
        self::assertSame(0, $bag->get('unknown'));
    }

    public function testNoProvidersYieldsEmptyBag(): void
    {
        $bag = (new MetricBagBuilder([]))->build('u1');

        self::assertSame(0, $bag->get('runs'));
    }

    /**
     * @param array<string, int> $facts
     */
    private function provider(array $facts): AchievementMetricProviderInterface
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
