<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Support\StatsPeriod;
use PHPUnit\Framework\TestCase;

/**
 * Story 42.1: the period of the admin statistics page.
 */
final class StatsPeriodTest extends TestCase
{
    public function testTwelveWeeksEndWithTheWeekInProgress(): void
    {
        // A Saturday: the week in progress started on Monday the 28th.
        $period = StatsPeriod::fromCode('12s', new \DateTimeImmutable('2026-10-03T15:30:00+02:00'));

        self::assertSame('week', $period->granularity);
        self::assertSame('2026-07-13T00:00:00+00:00', $period->start->format(\DATE_ATOM));
        self::assertSame('2026-10-05T00:00:00+00:00', $period->end->format(\DATE_ATOM));
        self::assertSame('2026-04-20T00:00:00+00:00', $period->previousStart->format(\DATE_ATOM));

        $series = $period->series(['2026-09-28' => 4, '2026-07-13' => 2]);
        self::assertCount(12, $series);
        self::assertSame(['start' => '2026-07-13', 'value' => 2, 'current' => false], $series[0]);
        self::assertSame(['start' => '2026-07-20', 'value' => 0, 'current' => false], $series[1]);
        self::assertSame(['start' => '2026-09-28', 'value' => 4, 'current' => true], $series[11]);
    }

    public function testAMondayAtMidnightUtcStartsANewWeek(): void
    {
        $period = StatsPeriod::fromCode('4s', new \DateTimeImmutable('2026-10-05T00:00:00+00:00'));

        self::assertSame('2026-09-14', $period->start->format('Y-m-d'));
        self::assertSame('2026-10-05', $period->series([])[3]['start']);
    }

    public function testWeeksCrossTheNewYear(): void
    {
        $period = StatsPeriod::fromCode('4s', new \DateTimeImmutable('2027-01-06T10:00:00+00:00'));

        self::assertSame(['2026-12-14', '2026-12-21', '2026-12-28', '2027-01-04'], array_column($period->series([]), 'start'));
    }

    public function testTwelveMonthsAreCutByMonth(): void
    {
        $period = StatsPeriod::fromCode('12m', new \DateTimeImmutable('2026-10-31T23:30:00+00:00'));

        self::assertSame('month', $period->granularity);
        self::assertSame('2025-11-01T00:00:00+00:00', $period->start->format(\DATE_ATOM));
        self::assertSame('2026-11-01T00:00:00+00:00', $period->end->format(\DATE_ATOM));
        self::assertSame('2024-11-01T00:00:00+00:00', $period->previousStart->format(\DATE_ATOM));
        $series = $period->series([]);
        self::assertCount(12, $series);
        self::assertSame('2026-10-01', $series[11]['start']);
    }

    public function testTheLocalTimeZoneDoesNotShiftTheBuckets(): void
    {
        // Monday 01:00 in Paris is still Sunday in UTC: the week in progress is the previous one.
        $period = StatsPeriod::fromCode('4s', new \DateTimeImmutable('2026-10-05T01:00:00+02:00'));

        self::assertSame('2026-09-28', $period->series([])[3]['start']);
    }

    public function testAnUnknownCodeFallsBackToTwelveWeeks(): void
    {
        self::assertSame('12s', StatsPeriod::fromCode('2ans', new \DateTimeImmutable('2026-10-03'))->code);
        self::assertSame('12s', StatsPeriod::fromCode(null, new \DateTimeImmutable('2026-10-03'))->code);
    }
}
