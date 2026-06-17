<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Domain\Membership;
use PHPUnit\Framework\TestCase;

final class MembershipTest extends TestCase
{
    private const START = '2026-01-01';
    private const EXPIRES = '2027-01-01';

    public function testCreateStartsActiveWithGivenDates(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 09:00:00');
        $membership = $this->make($now);

        self::assertSame('active', $membership->getStatus());
        self::assertSame('user-1', $membership->getUserId());
        self::assertSame('helloasso', $membership->getSource());
        self::assertEquals(new \DateTimeImmutable(self::EXPIRES), $membership->getExpiresAt());
        self::assertNull($membership->getReminder30SentAt());
        self::assertNull($membership->getReminder7SentAt());
    }

    public function testRenewUpdatesDatesAndKeepsOrderIdWhenNull(): void
    {
        $membership = $this->make(new \DateTimeImmutable(self::START), helloassoOrderId: 'order-1');

        $now = new \DateTimeImmutable('2026-06-01 12:00:00');
        $membership->renew(new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2027-06-01'), 'admin', null, null, $now);

        self::assertEquals(new \DateTimeImmutable('2027-06-01'), $membership->getExpiresAt());
        self::assertSame('admin', $membership->getSource());
        self::assertSame('order-1', $membership->getHelloassoOrderId()); // unchanged: null passed
        self::assertSame($now, $membership->getUpdatedAt());
    }

    public function testRenewOverwritesOrderIdAndNoteWhenProvided(): void
    {
        $membership = $this->make(new \DateTimeImmutable(self::START), helloassoOrderId: 'order-1');

        $membership->renew(new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2027-06-01'), 'helloasso', 'order-2', 'noted', new \DateTimeImmutable());

        self::assertSame('order-2', $membership->getHelloassoOrderId());
        self::assertSame('noted', $membership->getAdminNote());
    }

    public function testAdminEditSetsDatesAndNote(): void
    {
        $membership = $this->make(new \DateTimeImmutable(self::START));
        $now = new \DateTimeImmutable('2026-03-01');

        $membership->adminEdit(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-12-01'), 'edited', $now);

        self::assertEquals(new \DateTimeImmutable('2026-02-01'), $membership->getStartedAt());
        self::assertEquals(new \DateTimeImmutable('2026-12-01'), $membership->getExpiresAt());
        self::assertSame('edited', $membership->getAdminNote());
        self::assertSame($now, $membership->getUpdatedAt());
    }

    public function testExpireAndCancelChangeStatus(): void
    {
        $expired = $this->make(new \DateTimeImmutable(self::START));
        $expired->expire(new \DateTimeImmutable());
        self::assertSame('expired', $expired->getStatus());

        $cancelled = $this->make(new \DateTimeImmutable(self::START));
        $cancelled->cancel(new \DateTimeImmutable());
        self::assertSame('cancelled', $cancelled->getStatus());
    }

    public function testReminderMarkersRecordTimestamps(): void
    {
        $membership = $this->make(new \DateTimeImmutable(self::START));

        $at30 = new \DateTimeImmutable('2026-12-02');
        $membership->markReminder30Sent($at30);
        self::assertSame($at30, $membership->getReminder30SentAt());

        $at7 = new \DateTimeImmutable('2026-12-25');
        $membership->markReminder7Sent($at7);
        self::assertSame($at7, $membership->getReminder7SentAt());
    }

    private function make(\DateTimeImmutable $now, ?string $helloassoOrderId = null): Membership
    {
        return Membership::create(
            'user-1',
            new \DateTimeImmutable(self::START),
            new \DateTimeImmutable(self::EXPIRES),
            'helloasso',
            $helloassoOrderId,
            null,
            $now,
        );
    }
}
