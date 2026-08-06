<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Admin role transitions (story 36.1). They are deliberately separate from the member ones, and this
 * class pins the reason: the member transitions must keep refusing an admin account.
 */
final class UserAdminRoleTransitionsTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-08-06T10:00:00+00:00');
    }

    public function testPromoteToAdminGrantsTheRole(): void
    {
        $user = $this->makeUser(['ROLE_USER']);

        $user->promoteToAdmin($this->now);

        self::assertContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testPromoteToAdminKeepsTheMemberStatus(): void
    {
        $user = $this->makeUser(['ROLE_USER', 'ROLE_MEMBER']);

        $user->promoteToAdmin($this->now);

        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertContains('ROLE_MEMBER', $user->getRoles());
    }

    public function testDemoteFromAdminRemovesOnlyTheAdminRole(): void
    {
        // demoteToUser() resets the whole set; this one must not, or demoting an admin would silently
        // cost them their membership too.
        $user = $this->makeUser(['ROLE_USER', 'ROLE_MEMBER', 'ROLE_ADMIN']);

        $user->demoteFromAdmin($this->now);

        self::assertNotContains('ROLE_ADMIN', $user->getRoles());
        self::assertContains('ROLE_MEMBER', $user->getRoles());
        self::assertContains('ROLE_USER', $user->getRoles());
    }

    public function testMemberTransitionsStillRefuseAnAdminAccount(): void
    {
        // The guard that stops a stray "membre" click from destroying admin rights. Story 36.1 added
        // admin transitions next to these, it did not relax them.
        $admin = $this->makeUser(['ROLE_USER', 'ROLE_ADMIN']);

        $this->expectException(\DomainException::class);
        $admin->promoteToMember($this->now);
    }

    public function testDemoteToUserStillRefusesAnAdminAccount(): void
    {
        $admin = $this->makeUser(['ROLE_USER', 'ROLE_ADMIN']);

        $this->expectException(\DomainException::class);
        $admin->demoteToUser($this->now);
    }

    public function testAdminTransitionsRefuseADeletedAccount(): void
    {
        $user = $this->makeUser(['ROLE_USER']);
        $user->anonymizeForDeletion($this->now);

        $this->expectException(\DomainException::class);
        $user->promoteToAdmin($this->now);
    }

    /**
     * @param list<string> $roles
     */
    private function makeUser(array $roles): User
    {
        return new User(
            'user-1',
            'jean@example.org',
            'jean@example.org',
            'Jean',
            'hash',
            $roles,
            $this->now,
            $this->now,
            $this->now,
        );
    }
}
