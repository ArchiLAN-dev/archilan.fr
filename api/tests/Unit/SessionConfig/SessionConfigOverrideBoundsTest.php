<?php

declare(strict_types=1);

namespace App\Tests\Unit\SessionConfig;

use App\SessionConfig\Domain\ValueObject\SessionConfigOverride;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Story 41.39 AC 6: a pelle or bronze amount out of bounds is refused when the override is written, not left to
 * break the run's configuration later.
 */
final class SessionConfigOverrideBoundsTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, int>, string}>
     */
    public static function outOfBounds(): iterable
    {
        yield 'free item hint' => [['pelleItemHintPrice' => 0], 'invalid_pelle_hint_price'];
        yield 'location hint too dear' => [['pelleLocationHintPrice' => 1001], 'invalid_pelle_hint_price'];
        yield 'negative start' => [['bronzeStart' => -1], 'invalid_bronze_amount'];
        yield 'check too generous' => [['bronzePerCheck' => 5000], 'invalid_bronze_amount'];
        yield 'goal too generous' => [['bronzePerGoal' => 1001], 'invalid_bronze_amount'];
    }

    /**
     * @param array<string, int> $override
     */
    #[DataProvider('outOfBounds')]
    public function testAnAmountOutOfBoundsIsRefused(array $override, string $error): void
    {
        $this->expectExceptionObject(new \DomainException($error));

        SessionConfigOverride::fromArray($override);
    }

    public function testTheBoundsThemselvesAreAccepted(): void
    {
        $override = SessionConfigOverride::fromArray([
            'pelleItemHintPrice' => 1,
            'pelleLocationHintPrice' => 1000,
            'bronzeStart' => 0,
            'bronzePerCheck' => 1000,
            'bronzePerGoal' => 0,
        ]);

        self::assertSame(['pelleItemHintPrice' => 1, 'pelleLocationHintPrice' => 1000, 'bronzeStart' => 0, 'bronzePerCheck' => 1000, 'bronzePerGoal' => 0], $override->toArray());
    }
}
