<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events;

use App\Events\Domain\Entity\Event;
use PHPUnit\Framework\TestCase;

/** Story 3.15: the event the admin features on the home page. */
final class EventFeaturedOnHomeTest extends TestCase
{
    public function testANewEventIsNotFeatured(): void
    {
        self::assertFalse($this->event()->isFeaturedOnHome());
    }

    public function testAPublicEventIsFeaturedThenRemoved(): void
    {
        $event = $this->event();
        $event->transitionTo(Event::STATUS_PUBLISHED, $this->now());
        $later = new \DateTimeImmutable('2026-06-01T10:00:00+00:00');

        $event->featureOnHome($later);
        self::assertTrue($event->isFeaturedOnHome());
        self::assertEquals($later, $event->getUpdatedAt());

        $event->removeFromHome($later);
        self::assertFalse($event->isFeaturedOnHome());
    }

    public function testUnpublishingRemovesTheEventFromTheHomePage(): void
    {
        $event = $this->event();
        $event->transitionTo(Event::STATUS_PUBLISHED, $this->now());
        $event->featureOnHome($this->now());

        $event->transitionTo(Event::STATUS_DRAFT, $this->now());
        self::assertFalse($event->isFeaturedOnHome());

        $event->transitionTo(Event::STATUS_PUBLISHED, $this->now());
        self::assertFalse($event->isFeaturedOnHome());
    }

    public function testADraftCannotBeFeatured(): void
    {
        $this->expectExceptionObject(new \DomainException('event_featured_not_public'));

        $this->event()->featureOnHome($this->now());
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
