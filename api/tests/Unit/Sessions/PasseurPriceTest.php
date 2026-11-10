<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Domain\Service\PasseurPrice;
use PHPUnit\Framework\TestCase;

/** Story 52.4: the Passeur's price drops every quarter of an hour of BK, down to a floor. */
final class PasseurPriceTest extends TestCase
{
    private \DateTimeImmutable $since;

    protected function setUp(): void
    {
        $this->since = new \DateTimeImmutable('2026-11-09T14:00:00+01:00');
    }

    public function testThePriceIsFullDuringTheFirstQuarterOfAnHour(): void
    {
        self::assertSame(60, PasseurPrice::at(60, 10, 40, $this->since, $this->since->modify('+14 minutes 59 seconds')));
    }

    public function testThePriceDropsByTheDecayEachQuarterOfAnHour(): void
    {
        self::assertSame(54, PasseurPrice::at(60, 10, 40, $this->since, $this->since->modify('+15 minutes')));
        self::assertSame(48, PasseurPrice::at(60, 10, 40, $this->since, $this->since->modify('+30 minutes')));
    }

    public function testThePriceStopsAtTheFloor(): void
    {
        self::assertSame(24, PasseurPrice::at(60, 10, 40, $this->since, $this->since->modify('+10 hours')));
    }

    public function testThePriceRoundsUpAndNeverReachesZero(): void
    {
        self::assertSame(7, PasseurPrice::at(13, 50, 50, $this->since, $this->since->modify('+1 hour')));
        self::assertSame(1, PasseurPrice::at(1, 100, 0, $this->since, $this->since->modify('+1 hour')));
    }

    public function testTheShareRoundsDown(): void
    {
        self::assertSame(27, PasseurPrice::share(54, 50));
        self::assertSame(3, PasseurPrice::share(7, 50));
        self::assertSame(0, PasseurPrice::share(54, 0));
    }

    public function testTheDayRunsFromParisMidnightToParisMidnight(): void
    {
        [$start, $end] = PasseurPrice::dayOf(new \DateTimeImmutable('2026-11-09T23:30:00+00:00'));

        self::assertSame('2026-11-10T00:00:00+01:00', $start->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-11-11T00:00:00+01:00', $end->format(\DateTimeInterface::ATOM));
    }
}
