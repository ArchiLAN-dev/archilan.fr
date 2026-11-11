<?php

declare(strict_types=1);

namespace App\Tests\Unit\Registrations;

use App\Registrations\Domain\Entity\Registration;
use PHPUnit\Framework\TestCase;

/** Story 53.8: who gets the mail of the day before, and only once. */
final class RegistrationEveReminderTest extends TestCase
{
    private function registration(): Registration
    {
        return new Registration('reg-1', 'event-1', 'user-1', Registration::STATUS_RESERVED, new \DateTimeImmutable('2026-10-19 10:00'), new \DateTimeImmutable('2026-10-19 10:00'));
    }

    public function testAConfirmedRegistrationIsRemindedOnce(): void
    {
        $registration = $this->registration();
        $registration->confirm(new \DateTimeImmutable('2026-10-19 10:10'));
        self::assertTrue($registration->needsEveReminder());

        $registration->markEveReminded(new \DateTimeImmutable('2026-11-20 10:00'));

        self::assertFalse($registration->needsEveReminder());
        self::assertEquals(new \DateTimeImmutable('2026-11-20 10:00'), $registration->getEveReminderSentAt());
    }

    public function testAnUnconfirmedOrCancelledRegistrationIsNotReminded(): void
    {
        $registration = $this->registration();
        self::assertFalse($registration->needsEveReminder());

        $registration->confirm(new \DateTimeImmutable('2026-10-19 10:10'));
        $registration->cancel(new \DateTimeImmutable('2026-10-20 10:00'));
        self::assertFalse($registration->needsEveReminder());
    }

    public function testARegistrationThatStartsOverIsRemindedAgain(): void
    {
        $registration = $this->registration();
        $registration->confirm(new \DateTimeImmutable('2026-10-19 10:10'));
        $registration->markEveReminded(new \DateTimeImmutable('2026-11-20 10:00'));
        $registration->cancel(new \DateTimeImmutable('2026-11-20 12:00'));

        $registration->reactivate(new \DateTimeImmutable('2026-11-20 13:00'));

        self::assertNull($registration->getEveReminderSentAt());
    }
}
