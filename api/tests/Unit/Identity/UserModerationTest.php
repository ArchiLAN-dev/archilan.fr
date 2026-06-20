<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Domain\User;
use PHPUnit\Framework\TestCase;

final class UserModerationTest extends TestCase
{
    private function user(): User
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');

        return new User('u1', 'a@b.c', 'a@b.c', 'A', 'hash', ['ROLE_USER'], $now, $now, $now);
    }

    public function testFreshUserIsNotBlocked(): void
    {
        $now = new \DateTimeImmutable();
        $user = $this->user();
        self::assertFalse($user->isAccessBlocked($now));
        self::assertSame(User::MOD_ACTIVE, $user->moderationStatus($now));
    }

    public function testBanBlocksAndReportsBanned(): void
    {
        $now = new \DateTimeImmutable();
        $user = $this->user();
        $user->ban('spam', $now);
        self::assertTrue($user->isAccessBlocked($now));
        self::assertSame(User::MOD_BANNED, $user->moderationStatus($now));
        self::assertSame('spam', $user->getModerationReason());
    }

    public function testFutureSuspensionBlocksButPastSuspensionDoesNot(): void
    {
        $now = new \DateTimeImmutable('2026-06-20T12:00:00+00:00');
        $user = $this->user();

        $user->suspendUntil($now->modify('+1 day'), 'cooldown', $now);
        self::assertTrue($user->isAccessBlocked($now));
        self::assertSame(User::MOD_SUSPENDED, $user->moderationStatus($now));

        // Once the end date passes, access is restored without any further action.
        self::assertFalse($user->isAccessBlocked($now->modify('+2 days')));
        self::assertSame(User::MOD_ACTIVE, $user->moderationStatus($now->modify('+2 days')));
    }

    public function testBanThenSuspendClearsBanAndViceVersa(): void
    {
        $now = new \DateTimeImmutable('2026-06-20T12:00:00+00:00');
        $user = $this->user();

        $user->ban('x', $now);
        $user->suspendUntil($now->modify('+1 day'), 'y', $now);
        self::assertNull($user->getBannedAt());
        self::assertSame(User::MOD_SUSPENDED, $user->moderationStatus($now));

        $user->ban('z', $now);
        self::assertNull($user->getSuspendedUntil());
        self::assertSame(User::MOD_BANNED, $user->moderationStatus($now));
    }

    public function testLiftRestoresAccess(): void
    {
        $now = new \DateTimeImmutable();
        $user = $this->user();
        $user->ban('x', $now);
        $user->lift($now);
        self::assertFalse($user->isAccessBlocked($now));
        self::assertNull($user->getModerationReason());
        self::assertSame(User::MOD_ACTIVE, $user->moderationStatus($now));
    }
}
