<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Application\ExpireMembership;
use App\Membership\Application\Message\MembershipExpiredNotificationMessage;
use App\Membership\Application\Message\SyncMemberToDolibarrMessage;
use App\Membership\Application\UserRoleGatewayInterface;
use App\Membership\Domain\Membership;
use Doctrine\ORM\EntityManagerInterface;
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

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('find')
            ->with(Membership::class, self::MEMBERSHIP_ID)
            ->willReturn($membership);

        $gateway = $this->createMock(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => self::DISCORD_ID, 'roles' => ['ROLE_USER']]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(3))
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $logger = $this->createStub(LoggerInterface::class);

        $service = new ExpireMembership($em, $gateway, $bus, $logger);
        $service->expire(self::MEMBERSHIP_ID);

        self::assertSame('expired', $membership->getStatus());
    }

    public function testExpireIsNoOpWhenAlreadyExpired(): void
    {
        $membership = Membership::create(self::USER_ID, new \DateTimeImmutable('2024-01-01'), new \DateTimeImmutable('2025-01-01'), 'admin', null, null, new \DateTimeImmutable('2024-01-01'));
        $membership->expire(new \DateTimeImmutable('2025-01-02'));

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($membership);

        $gateway = $this->createStub(UserRoleGatewayInterface::class);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $logger = $this->createStub(LoggerInterface::class);

        $service = new ExpireMembership($em, $gateway, $bus, $logger);
        $service->expire(self::MEMBERSHIP_ID);
    }

    public function testExpireIsNoOpWhenMembershipNotFound(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $gateway = $this->createStub(UserRoleGatewayInterface::class);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $logger = $this->createStub(LoggerInterface::class);

        $service = new ExpireMembership($em, $gateway, $bus, $logger);
        $service->expire(self::MEMBERSHIP_ID);
    }

    public function testExpireNoDiscordSyncWhenNoDiscordIdButNotificationStillDispatched(): void
    {
        $membership = Membership::create(self::USER_ID, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2026-01-01'), 'admin', null, null, new \DateTimeImmutable('2025-01-01'));

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($membership);

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

        $logger = $this->createStub(LoggerInterface::class);

        $service = new ExpireMembership($em, $gateway, $bus, $logger);
        $service->expire(self::MEMBERSHIP_ID);
    }
}
