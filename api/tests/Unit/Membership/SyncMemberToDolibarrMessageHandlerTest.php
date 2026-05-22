<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use App\Membership\Application\DolibarrClientInterface;
use App\Membership\Application\Handler\SyncMemberToDolibarrMessageHandler;
use App\Membership\Application\Message\SyncMemberToDolibarrMessage;
use App\Membership\Domain\Membership;
use App\Membership\Domain\MembershipRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SyncMemberToDolibarrMessageHandlerTest extends TestCase
{
    public function testHandleSyncsToDolibarrWhenMembershipFound(): void
    {
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $membership = Membership::create('user-1', $now, new \DateTimeImmutable('2027-05-16T10:00:00+00:00'), 'admin', null, null, $now);

        $user = $this->createStub(User::class);
        $user->method('getEmail')->willReturn('jean@example.org');
        $user->method('getDisplayName')->willReturn('Jean');
        $user->method('getDeletedAt')->willReturn(null);

        $memberships = $this->createStub(MembershipRepositoryInterface::class);
        $memberships->method('findById')->willReturn($membership);

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($user);

        $dolibarr = $this->createMock(DolibarrClientInterface::class);
        $dolibarr->expects(self::once())->method('upsertMember')->with(
            'jean@example.org',
            'Jean',
            'active',
            self::isInstanceOf(\DateTimeImmutable::class),
        );

        $handler = new SyncMemberToDolibarrMessageHandler(
            $memberships,
            $users,
            $dolibarr,
            $this->createStub(LoggerInterface::class),
        );

        $handler(new SyncMemberToDolibarrMessage('membership-id-1'));
    }

    public function testHandleReturnsEarlyWhenMembershipNotFound(): void
    {
        $memberships = $this->createStub(MembershipRepositoryInterface::class);
        $memberships->method('findById')->willReturn(null);

        $dolibarr = $this->createMock(DolibarrClientInterface::class);
        $dolibarr->expects(self::never())->method('upsertMember');

        $handler = new SyncMemberToDolibarrMessageHandler(
            $memberships,
            $this->createStub(UserRepositoryInterface::class),
            $dolibarr,
            $this->createStub(LoggerInterface::class),
        );

        $handler(new SyncMemberToDolibarrMessage('missing-id'));
    }

    public function testHandleRethrowsOnDolibarrFailure(): void
    {
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $membership = Membership::create('user-1', $now, new \DateTimeImmutable('2027-05-16T10:00:00+00:00'), 'admin', null, null, $now);

        $user = $this->createStub(User::class);
        $user->method('getEmail')->willReturn('jean@example.org');
        $user->method('getDisplayName')->willReturn('Jean');
        $user->method('getDeletedAt')->willReturn(null);

        $memberships = $this->createStub(MembershipRepositoryInterface::class);
        $memberships->method('findById')->willReturn($membership);

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($user);

        $dolibarr = $this->createMock(DolibarrClientInterface::class);
        $dolibarr->expects(self::once())->method('upsertMember')
            ->willThrowException(new \RuntimeException('Dolibarr unreachable'));

        $handler = new SyncMemberToDolibarrMessageHandler(
            $memberships,
            $users,
            $dolibarr,
            $this->createStub(LoggerInterface::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dolibarr unreachable');

        $handler(new SyncMemberToDolibarrMessage('membership-id-1'));
    }

    public function testHandleSyncsExpiredMembership(): void
    {
        $now = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $membership = Membership::create('user-1', $now, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), 'admin', null, null, $now);
        $membership->expire(new \DateTimeImmutable('2026-01-02T00:00:00+00:00'));

        $user = $this->createStub(User::class);
        $user->method('getEmail')->willReturn('jean@example.org');
        $user->method('getDisplayName')->willReturn('Jean');
        $user->method('getDeletedAt')->willReturn(null);

        $memberships = $this->createStub(MembershipRepositoryInterface::class);
        $memberships->method('findById')->willReturn($membership);

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($user);

        $dolibarr = $this->createMock(DolibarrClientInterface::class);
        $dolibarr->expects(self::once())->method('upsertMember')->with(
            'jean@example.org',
            'Jean',
            'expired',
            self::isInstanceOf(\DateTimeImmutable::class),
        );

        $handler = new SyncMemberToDolibarrMessageHandler(
            $memberships,
            $users,
            $dolibarr,
            $this->createStub(LoggerInterface::class),
        );

        $handler(new SyncMemberToDolibarrMessage('membership-id-2'));
    }
}
