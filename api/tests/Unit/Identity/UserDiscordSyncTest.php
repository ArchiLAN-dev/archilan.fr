<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Domain\User;
use PHPUnit\Framework\TestCase;

final class UserDiscordSyncTest extends TestCase
{
    private function makeUser(): User
    {
        return User::register(
            'test@example.com',
            'test@example.com',
            'hashed',
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
    }

    public function testMarkDiscordSyncSuccessSetsSyncedAtAndClearsError(): void
    {
        $user = $this->makeUser();
        $at = new \DateTimeImmutable('2026-05-16T12:00:00Z');

        $user->markDiscordSyncSuccess($at);

        $this->assertSame($at, $user->getDiscordRoleSyncedAt());
        $this->assertNull($user->getDiscordSyncError());
    }

    public function testMarkDiscordSyncSuccessOverwritesPreviousError(): void
    {
        $user = $this->makeUser();
        $user->markDiscordSyncFailure('some error', new \DateTimeImmutable('2026-05-16T11:00:00Z'));
        $at = new \DateTimeImmutable('2026-05-16T12:00:00Z');

        $user->markDiscordSyncSuccess($at);

        $this->assertSame($at, $user->getDiscordRoleSyncedAt());
        $this->assertNull($user->getDiscordSyncError());
    }

    public function testMarkDiscordSyncFailureSetsErrorAndLeavesTimestampUnchanged(): void
    {
        $user = $this->makeUser();
        $successAt = new \DateTimeImmutable('2026-05-16T11:00:00Z');
        $failureAt = new \DateTimeImmutable('2026-05-16T12:00:00Z');
        $user->markDiscordSyncSuccess($successAt);

        $user->markDiscordSyncFailure('Discord API unreachable', $failureAt);

        $this->assertSame('Discord API unreachable', $user->getDiscordSyncError());
        $this->assertSame($successAt, $user->getDiscordRoleSyncedAt());
        $this->assertSame($failureAt, $user->getUpdatedAt());
    }

    public function testMarkDiscordSyncFailureWithNoPreexistingSyncedAtLeavesItNull(): void
    {
        $user = $this->makeUser();

        $user->markDiscordSyncFailure('Connection refused', new \DateTimeImmutable());

        $this->assertSame('Connection refused', $user->getDiscordSyncError());
        $this->assertNull($user->getDiscordRoleSyncedAt());
    }
}
