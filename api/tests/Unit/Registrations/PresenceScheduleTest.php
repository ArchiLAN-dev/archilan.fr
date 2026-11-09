<?php

declare(strict_types=1);

namespace App\Tests\Unit\Registrations;

use App\Registrations\Domain\Service\PresenceSchedule;
use PHPUnit\Framework\TestCase;

final class PresenceScheduleTest extends TestCase
{
    private \DateTimeImmutable $start;
    private \DateTimeImmutable $end;

    protected function setUp(): void
    {
        $this->start = new \DateTimeImmutable('2027-06-04T16:00:00+00:00');
        $this->end = new \DateTimeImmutable('2027-06-06T18:00:00+00:00');
    }

    public function testNoWindowAndWindowsThatOnlyTouchAreFine(): void
    {
        self::assertNull(PresenceSchedule::problem([], $this->start, $this->end));
        self::assertNull(PresenceSchedule::problem([
            [self::at('2027-06-05T12:00'), self::at('2027-06-06T18:00')],
            [self::at('2027-06-04T16:00'), self::at('2027-06-05T12:00')],
        ], $this->start, $this->end));
    }

    public function testEachRuleIsReported(): void
    {
        self::assertSame(PresenceSchedule::INVERTED, PresenceSchedule::problem([[self::at('2027-06-05T12:00'), self::at('2027-06-05T12:00')]], $this->start, $this->end));
        self::assertSame(PresenceSchedule::OUTSIDE_EVENT, PresenceSchedule::problem([[self::at('2027-06-04T15:59'), self::at('2027-06-05T12:00')]], $this->start, $this->end));
        self::assertSame(PresenceSchedule::OUTSIDE_EVENT, PresenceSchedule::problem([[self::at('2027-06-06T12:00'), self::at('2027-06-06T18:01')]], $this->start, $this->end));
        self::assertSame(PresenceSchedule::OVERLAP, PresenceSchedule::problem([
            [self::at('2027-06-05T20:00'), self::at('2027-06-06T10:00')],
            [self::at('2027-06-05T08:00'), self::at('2027-06-05T20:01')],
        ], $this->start, $this->end));

        $many = [];
        for ($hour = 0; $hour <= PresenceSchedule::MAX_WINDOWS; ++$hour) {
            $many[] = [$this->start->modify("+{$hour} hours"), $this->start->modify("+{$hour} hours 30 minutes")];
        }
        self::assertSame(PresenceSchedule::TOO_MANY, PresenceSchedule::problem($many, $this->start, $this->end));
    }

    private function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc.':00+00:00');
    }
}
