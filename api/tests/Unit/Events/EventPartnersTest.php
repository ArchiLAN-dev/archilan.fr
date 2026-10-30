<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events;

use App\Events\Domain\Entity\Event;
use PHPUnit\Framework\TestCase;

/** Story 45.3: the partners thanked on an event. */
final class EventPartnersTest extends TestCase
{
    private const string FIRST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string SECOND = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testANewEventHasNoPartner(): void
    {
        self::assertSame([], $this->event()->getPartners());
    }

    public function testChoosePartnersKeepsTheOrderAndTrimsTheMention(): void
    {
        $event = $this->event();
        $later = new \DateTimeImmutable('2026-06-01T10:00:00+00:00');

        $event->choosePartners([
            ['partnerId' => self::SECOND, 'mention' => '  Lots offerts  '],
            ['partnerId' => self::FIRST, 'mention' => '   '],
        ], $later);

        self::assertSame([
            ['partnerId' => self::SECOND, 'mention' => 'Lots offerts'],
            ['partnerId' => self::FIRST, 'mention' => null],
        ], $event->getPartners());
        self::assertEquals($later, $event->getUpdatedAt());
    }

    public function testAnEmptyListClearsThePartners(): void
    {
        $event = $this->event();
        $event->choosePartners([['partnerId' => self::FIRST, 'mention' => null]], $this->now());

        $event->choosePartners([], $this->now());

        self::assertSame([], $event->getPartners());
    }

    public function testMoreThanFivePartnersIsRefused(): void
    {
        $partners = array_map(
            static fn (int $i): array => ['partnerId' => str_repeat((string) $i, 32), 'mention' => null],
            range(1, 6),
        );

        $this->expectExceptionObject(new \DomainException('event_partners_too_many'));
        $this->event()->choosePartners($partners, $this->now());
    }

    public function testAnInvalidPartnerIdIsRefused(): void
    {
        $this->expectExceptionObject(new \DomainException('event_partner_id_invalid'));
        $this->event()->choosePartners([['partnerId' => 'not-an-id', 'mention' => null]], $this->now());
    }

    public function testADuplicatePartnerIsRefused(): void
    {
        $this->expectExceptionObject(new \DomainException('event_partner_duplicate'));
        $this->event()->choosePartners([
            ['partnerId' => self::FIRST, 'mention' => null],
            ['partnerId' => self::FIRST, 'mention' => 'Encore'],
        ], $this->now());
    }

    public function testATooLongMentionIsRefused(): void
    {
        $this->expectExceptionObject(new \DomainException('event_partner_mention_too_long'));
        $this->event()->choosePartners([
            ['partnerId' => self::FIRST, 'mention' => str_repeat('é', Event::PARTNER_MENTION_MAX_LENGTH + 1)],
        ], $this->now());
    }

    public function testAMentionAtTheLimitIsAccepted(): void
    {
        $event = $this->event();
        $mention = str_repeat('é', Event::PARTNER_MENTION_MAX_LENGTH);

        $event->choosePartners([['partnerId' => self::FIRST, 'mention' => $mention]], $this->now());

        self::assertSame($mention, $event->getPartners()[0]['mention']);
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
