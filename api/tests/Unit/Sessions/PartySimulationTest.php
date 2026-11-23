<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Domain\Service\PartySimulation;
use PHPUnit\Framework\TestCase;

/**
 * Story 56.4: the party simulation on hand-computed cases.
 */
final class PartySimulationTest extends TestCase
{
    private const float DELTA = 0.001;

    public function testAChainMakesTheLastSlotWaitForTheMiddleOne(): void
    {
        // A (10 h) finds B's item in sphere 1, B (10 h) finds C's in sphere 2.
        $result = PartySimulation::run(['A' => 10.0, 'B' => 10.0, 'C' => 10.0], [
            ['sphere' => 1, 'finder' => 'A', 'receiver' => 'B'],
            ['sphere' => 2, 'finder' => 'B', 'receiver' => 'C'],
        ]);

        // A plays freely, its item leaves halfway (5 h). B's second half starts at 5 h: no wait.
        self::assertEqualsWithDelta(10.0, $result->slots['A']->finishHours, self::DELTA);
        self::assertEqualsWithDelta(0.0, $result->slots['A']->bkHours, self::DELTA);
        self::assertEqualsWithDelta(10.0, $result->slots['B']->finishHours, self::DELTA);
        self::assertEqualsWithDelta(0.0, $result->slots['B']->bkHours, self::DELTA);
        // B finds C's item halfway through its second half (7.5 h); C is done with its first half at 5 h.
        self::assertEqualsWithDelta(12.5, $result->slots['C']->finishHours, self::DELTA);
        self::assertEqualsWithDelta(2.5, $result->slots['C']->bkHours, self::DELTA);
        self::assertSame(['B'], array_keys($result->slots['C']->waitsOn));
        self::assertSame('C', $result->criticalSlot);
        self::assertEqualsWithDelta(12.5, $result->endHours, self::DELTA);
    }

    public function testACrossedWaitChargesTheShortWorldToTheLongOne(): void
    {
        // A short world (2 h) needs two items of a long one (20 h), which needs one of A's in between.
        $result = PartySimulation::run(['A' => 2.0, 'B' => 20.0], [
            ['sphere' => 1, 'finder' => 'B', 'receiver' => 'A'],
            ['sphere' => 2, 'finder' => 'A', 'receiver' => 'B'],
            ['sphere' => 3, 'finder' => 'B', 'receiver' => 'A'],
        ]);

        // A: parts of 2/3 h; waits for B's sphere 1 item (5 h), then for its sphere 3 item (15 h).
        self::assertEqualsWithDelta(15.0 + 2.0 / 3, $result->slots['A']->finishHours, self::DELTA);
        self::assertEqualsWithDelta(5.0 - 2.0 / 3 + 15.0 - (5.0 + 2.0 / 3), $result->slots['A']->bkHours, self::DELTA);
        self::assertSame(['B'], array_keys($result->slots['A']->waitsOn));
        // B gets A's item at 5 h 20, long before its second half (10 h).
        self::assertEqualsWithDelta(20.0, $result->slots['B']->finishHours, self::DELTA);
        self::assertEqualsWithDelta(0.0, $result->slots['B']->bkHours, self::DELTA);
        self::assertSame('B', $result->criticalSlot);
    }

    public function testAnAutonomousSlotPlaysAtItsOwnPace(): void
    {
        $result = PartySimulation::run(['Solo' => 6.0, 'A' => 3.0], [
            ['sphere' => 1, 'finder' => 'A', 'receiver' => 'A'],
            // A player outside the party is ignored.
            ['sphere' => 1, 'finder' => 'Ghost', 'receiver' => 'Solo'],
        ]);

        self::assertEqualsWithDelta(6.0, $result->slots['Solo']->finishHours, self::DELTA);
        self::assertEqualsWithDelta(0.0, $result->slots['Solo']->bkHours, self::DELTA);
        self::assertSame([], $result->slots['Solo']->waitsOn);
        // Its own items never make a slot wait.
        self::assertEqualsWithDelta(3.0, $result->slots['A']->finishHours, self::DELTA);
        self::assertEqualsWithDelta(0.0, $result->slots['A']->bkHours, self::DELTA);
        self::assertSame('Solo', $result->criticalSlot);
    }
}
