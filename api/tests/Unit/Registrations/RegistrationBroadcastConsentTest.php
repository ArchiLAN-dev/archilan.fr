<?php

declare(strict_types=1);

namespace App\Tests\Unit\Registrations;

use App\Registrations\Domain\Entity\Registration;
use PHPUnit\Framework\TestCase;

/** Story 47.1: the member lets the ArchiLAN broadcast show their stream. */
final class RegistrationBroadcastConsentTest extends TestCase
{
    public function testConsentIsOffByDefaultThenFollowsTheMember(): void
    {
        $registration = $this->registration();
        self::assertFalse($registration->hasBroadcastConsent());

        $now = new \DateTimeImmutable('2026-10-21 18:00');
        $registration->chooseBroadcastConsent(true, $now);
        self::assertTrue($registration->hasBroadcastConsent());
        self::assertEquals($now, $registration->getUpdatedAt());

        $registration->chooseBroadcastConsent(false, $now);
        self::assertFalse($registration->hasBroadcastConsent());
    }

    public function testACancelledSeatCannotConsent(): void
    {
        $registration = $this->registration();
        $registration->cancel(new \DateTimeImmutable('2026-10-20 09:00'));

        $this->expectException(\DomainException::class);
        $registration->chooseBroadcastConsent(true, new \DateTimeImmutable('2026-10-21 18:00'));
    }

    public function testRegisteringAgainStartsWithoutConsent(): void
    {
        $registration = $this->registration();
        $registration->chooseBroadcastConsent(true, new \DateTimeImmutable('2026-10-19 11:00'));
        $registration->cancel(new \DateTimeImmutable('2026-10-20 09:00'));

        $registration->reactivate(new \DateTimeImmutable('2026-10-21 18:00'));

        self::assertFalse($registration->hasBroadcastConsent());
    }

    private function registration(): Registration
    {
        return new Registration('reg-1', 'event-1', 'user-1', Registration::STATUS_RESERVED, new \DateTimeImmutable('2026-10-19 10:00'), new \DateTimeImmutable('2026-10-19 10:00'));
    }
}
