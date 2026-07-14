<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Application\Command\ExpireMembership;
use App\Membership\Application\Message\MembershipExpiredNotificationMessage;
use App\Membership\Application\Message\SyncMemberToDolibarrMessage;
use App\Membership\Application\Port\UserRoleGatewayInterface;
use App\Membership\Domain\Entity\Membership;
use App\Membership\Domain\Repository\MembershipRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ExpireMembershipTest extends TestCase
{
    private const string MEMBERSHIP_ID = 'membership-abc';
    private const string USER_ID = 'user-abc123';
    private const string DISCORD_ID = 'discord-xyz';

    public function testExpireSetsStatusExpiredAndDispatchesSync(): void
    {
        $membership = Membership::create(self::USER_ID, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2026-01-01'), 'admin', null, null, new \DateTimeImmutable('2025-01-01'));

        $memberships = $this->createMock(MembershipRepositoryInterface::class);
        $memberships->expects(self::once())
            ->method('findById')
            ->with(self::MEMBERSHIP_ID)
            ->willReturn($membership);
        $memberships->expects(self::once())->method('flush');

        $gateway = self::createStub(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => self::DISCORD_ID, 'roles' => ['ROLE_USER']]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $service = new ExpireMembership($memberships, $gateway, $bus, self::createStub(LoggerInterface::class), new MockClock());
        $service->expire(self::MEMBERSHIP_ID);

        self::assertSame('expired', $membership->getStatus());
    }

    public function testExpireIsNoOpWhenAlreadyExpired(): void
    {
        $membership = Membership::create(self::USER_ID, new \DateTimeImmutable('2024-01-01'), new \DateTimeImmutable('2025-01-01'), 'admin', null, null, new \DateTimeImmutable('2024-01-01'));
        $membership->expire(new \DateTimeImmutable('2025-01-02'));

        $memberships = self::createStub(MembershipRepositoryInterface::class);
        $memberships->method('findById')->willReturn($membership);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $service = new ExpireMembership($memberships, self::createStub(UserRoleGatewayInterface::class), $bus, self::createStub(LoggerInterface::class), new MockClock());
        $service->expire(self::MEMBERSHIP_ID);
    }

    public function testExpireIsNoOpWhenMembershipNotFound(): void
    {
        $memberships = self::createStub(MembershipRepositoryInterface::class);
        $memberships->method('findById')->willReturn(null);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $service = new ExpireMembership($memberships, self::createStub(UserRoleGatewayInterface::class), $bus, self::createStub(LoggerInterface::class), new MockClock());
        $service->expire(self::MEMBERSHIP_ID);
    }

    public function testExpireNoDiscordSyncWhenNoDiscordIdButNotificationStillDispatched(): void
    {
        $membership = Membership::create(self::USER_ID, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2026-01-01'), 'admin', null, null, new \DateTimeImmutable('2025-01-01'));

        $memberships = self::createStub(MembershipRepositoryInterface::class);
        $memberships->method('findById')->willReturn($membership);

        $gateway = self::createStub(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => null, 'roles' => ['ROLE_USER']]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(2))
            ->method('dispatch')
            ->with(self::logicalOr(
                self::isInstanceOf(MembershipExpiredNotificationMessage::class),
                self::isInstanceOf(SyncMemberToDolibarrMessage::class),
            ))
            ->willReturn(new Envelope(new \stdClass()));

        $service = new ExpireMembership($memberships, $gateway, $bus, self::createStub(LoggerInterface::class), new MockClock());
        $service->expire(self::MEMBERSHIP_ID);
    }
}
