<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events;

use App\Events\Domain\Entity\Event;
use PHPUnit\Framework\TestCase;

/** Story 53.4: the registrants-only practical information of an event. */
final class EventPracticalInfoTest extends TestCase
{
    public function testANewEventHasNoPracticalInfo(): void
    {
        $event = $this->event();

        self::assertNull($event->getPracticalInfo());
        self::assertNull($event->getPracticalInfoUpdatedAt());
    }

    public function testWritingTrimsAndDatesTheText(): void
    {
        $event = $this->event();
        $later = $this->now()->modify('+1 hour');

        $event->writePracticalInfo("  ## Wifi\nArchiLAN  ", $later);

        self::assertSame("## Wifi\nArchiLAN", $event->getPracticalInfo());
        self::assertEquals($later, $event->getPracticalInfoUpdatedAt());
        self::assertEquals($later, $event->getUpdatedAt());
    }

    public function testTheSameTextKeepsItsDate(): void
    {
        $event = $this->event();
        $first = $this->now()->modify('+1 hour');
        $event->writePracticalInfo('## Wifi', $first);

        $event->writePracticalInfo(' ## Wifi ', $this->now()->modify('+2 hours'));

        self::assertEquals($first, $event->getPracticalInfoUpdatedAt());
    }

    public function testBlankTextRemovesTheInformation(): void
    {
        $event = $this->event();
        $event->writePracticalInfo('## Wifi', $this->now()->modify('+1 hour'));

        $event->writePracticalInfo('   ', $this->now()->modify('+2 hours'));

        self::assertNull($event->getPracticalInfo());
        self::assertNull($event->getPracticalInfoUpdatedAt());
    }

    public function testTextAtTheLimitIsAccepted(): void
    {
        $event = $this->event();

        $event->writePracticalInfo(str_repeat('é', Event::PRACTICAL_INFO_MAX_LENGTH), $this->now());

        self::assertSame(Event::PRACTICAL_INFO_MAX_LENGTH, mb_strlen((string) $event->getPracticalInfo()));
    }

    public function testTextOverTheLimitIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('event_practical_info_too_long');

        $this->event()->writePracticalInfo(str_repeat('a', Event::PRACTICAL_INFO_MAX_LENGTH + 1), $this->now());
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
    }

    private function event(): Event
    {
        $now = $this->now();

        return Event::draft('LAN', 'desc', $now->modify('+10 days'), $now->modify('+11 days'), 'Clermont', 40, $now, $now->modify('+9 days'), true, $now);
    }
}
