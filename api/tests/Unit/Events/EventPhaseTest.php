<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Service\EventPhase;
use PHPUnit\Framework\TestCase;

/** Story 53.2: where an event stands for its registrants, same cases as `eventPhase` in the frontend (53.1). */
final class EventPhaseTest extends TestCase
{
    private \DateTimeImmutable $startsAt;
    private \DateTimeImmutable $endsAt;

    protected function setUp(): void
    {
        $this->startsAt = new \DateTimeImmutable('2026-11-21T09:00:00+00:00');
        $this->endsAt = new \DateTimeImmutable('2026-11-22T17:00:00+00:00');
    }

    public function testBeforeUntilTheStart(): void
    {
        self::assertSame(EventPhase::BEFORE, $this->phase(Event::STATUS_PUBLISHED, '2026-11-21T08:59:59+00:00'));
    }

    public function testDuringFromTheStartToTheEnd(): void
    {
        self::assertSame(EventPhase::DURING, $this->phase(Event::STATUS_PUBLISHED, '2026-11-21T09:00:00+00:00'));
        self::assertSame(EventPhase::DURING, $this->phase(Event::STATUS_PUBLISHED, '2026-11-22T17:00:00+00:00'));
    }

    public function testAfterTheEnd(): void
    {
        self::assertSame(EventPhase::AFTER, $this->phase(Event::STATUS_PUBLISHED, '2026-11-22T17:00:01+00:00'));
    }

    public function testTheAdminStatusWins(): void
    {
        self::assertSame(EventPhase::DURING, $this->phase(Event::STATUS_IN_PROGRESS, '2026-11-20T12:00:00+00:00'));
        self::assertSame(EventPhase::AFTER, $this->phase(Event::STATUS_COMPLETED, '2026-11-22T12:00:00+00:00'));
    }

    private function phase(string $status, string $now): string
    {
        return EventPhase::at($status, $this->startsAt, $this->endsAt, new \DateTimeImmutable($now));
    }
}
