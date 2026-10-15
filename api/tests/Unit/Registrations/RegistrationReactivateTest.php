<?php

declare(strict_types=1);

namespace App\Tests\Unit\Registrations;

use App\Registrations\Domain\Entity\Registration;
use PHPUnit\Framework\TestCase;

/** Story 4.22: a member who cancelled registers again on the same row. */
final class RegistrationReactivateTest extends TestCase
{
    public function testReactivatedRegistrationStartsOver(): void
    {
        $registration = new Registration('reg-1', 'event-1', 'user-1', Registration::STATUS_RESERVED, new \DateTimeImmutable('2026-10-19 10:00'), new \DateTimeImmutable('2026-10-19 10:00'));
        $registration->replaceSlots([['slotId' => 'slot-1', 'gameId' => 'game-1', 'playerYaml' => "name: x\n"]], new \DateTimeImmutable('2026-10-19 10:05'));
        $registration->confirm(new \DateTimeImmutable('2026-10-19 10:10'));
        $registration->cancel(new \DateTimeImmutable('2026-10-20 09:00'));

        $now = new \DateTimeImmutable('2026-10-21 18:00');
        $registration->reactivate($now);

        self::assertTrue($registration->isReserved());
        self::assertSame([], $registration->getGameSlots());
        self::assertNull($registration->getSubmittedAt());
        self::assertEquals($now, $registration->getCreatedAt());
        self::assertSame('reg-1', $registration->getId());
    }

    public function testOnlyACancelledRegistrationCanBeReactivated(): void
    {
        $registration = new Registration('reg-1', 'event-1', 'user-1', Registration::STATUS_RESERVED, new \DateTimeImmutable('2026-10-19 10:00'), new \DateTimeImmutable('2026-10-19 10:00'));

        $this->expectException(\DomainException::class);
        $registration->reactivate(new \DateTimeImmutable('2026-10-21 18:00'));
    }
}
