<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Application\ExpireMembership;
use App\Membership\Application\Message\MembershipExpiredNotificationMessage;
use App\Membership\Application\Message\SyncMemberToDolibarrMessage;
use App\Membership\Application\UserRoleGatewayInterface;
use App\Membership\Domain\Membership;
use App\Membership\Domain\MembershipRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ExpireMembershipTest extends TestCase
{
    private const MEMBERSHIP_ID = 'membership-abc';
    private const USER_ID = 'user-abc123';
    private const DISCORD_ID = 'discord-xyz';

    public function testExpireSetsStatusExpiredAndDispatchesSync(): void
    {
        $membership = Membership::create(self::USER_ID, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2026-01-01'), 'admin', null, null, new \DateTimeImmutable('2025-01-01'));

        $memberships = $this->createMock(MembershipRepositoryInterface::class);
        $memberships->expects($this->once())
            ->method('findById')
            ->with(self::MEMBERSHIP_ID)
            ->willReturn($membership);
        $memberships->expects($this->once())->method('flush');

        $gateway = $this->createMock(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => self::DISCORD_ID, 'roles' => ['ROLE_USER']]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(3))
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $service = new ExpireMembership($memberships, $gateway, $bus, $this->createStub(LoggerInterface::class));
        $service->expire(self::MEMBERSHIP_ID);

        self::assertSame('expired', $membership->getStatus());
    }

    public function testExpireIsNoOpWhenAlreadyExpired(): void
    {
        $membership = Membership::create(self::USER_ID, new \DateTimeImmutable('2024-01-01'), new \DateTimeImmutable('2025-01-01'), 'admin', null, null, new \DateTimeImmutable('2024-01-01'));
        $membership->expire(new \DateTimeImmutable('2025-01-02'));

        $memberships = $this->createStub(MembershipRepositoryInterface::class);
        $memberships->method('findById')->willReturn($membership);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $service = new ExpireMembership($memberships, $this->createStub(UserRoleGatewayInterface::class), $bus, $this->createStub(LoggerInterface::class));
        $service->expire(self::MEMBERSHIP_ID);
    }

    public function testExpireIsNoOpWhenMembershipNotFound(): void
    {
        $memberships = $this->createStub(MembershipRepositoryInterface::class);
        $memberships->method('findById')->willReturn(null);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $service = new ExpireMembership($memberships, $this->createStub(UserRoleGatewayInterface::class), $bus, $this->createStub(LoggerInterface::class));
        $service->expire(self::MEMBERSHIP_ID);
    }

    public function testExpireNoDiscordSyncWhenNoDiscordIdButNotificationStillDispatched(): void
    {
        $membership = Membership::create(self::USER_ID, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2026-01-01'), 'admin', null, null, new \DateTimeImmutable('2025-01-01'));

        $memberships = $this->createStub(MembershipRepositoryInterface::class);
        $memberships->method('findById')->willReturn($membership);

        $gateway = $this->createStub(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => null, 'roles' => ['ROLE_USER']]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(2))
            ->method('dispatch')
            ->with($this->logicalOr(
                $this->isInstanceOf(MembershipExpiredNotificationMessage::class),
                $this->isInstanceOf(SyncMemberToDolibarrMessage::class),
            ))
            ->willReturn(new Envelope(new \stdClass()));

        $service = new ExpireMembership($memberships, $gateway, $bus, $this->createStub(LoggerInterface::class));
        $service->expire(self::MEMBERSHIP_ID);
    }
}
