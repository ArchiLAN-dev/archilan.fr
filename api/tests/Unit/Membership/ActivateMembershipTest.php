<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Application\ActivateMembership;
use App\Membership\Application\Message\MembershipActivatedNotificationMessage;
use App\Membership\Application\Message\SyncMemberToDolibarrMessage;
use App\Membership\Application\UserRoleGatewayInterface;
use App\Membership\Domain\Membership;
use App\Membership\Domain\MembershipRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ActivateMembershipTest extends TestCase
{
    private const USER_ID = 'user-abc123';
    private const DISCORD_ID = 'discord-xyz';

    public function testActivateCreatesNewMembershipAndDispatchesSync(): void
    {
        $memberships = $this->createMock(MembershipRepositoryInterface::class);
        $memberships->method('findActiveByUserId')->willReturn(null);
        $memberships->expects($this->once())->method('save')->with($this->isInstanceOf(Membership::class));

        $gateway = $this->createStub(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => self::DISCORD_ID, 'roles' => ['ROLE_USER', 'ROLE_MEMBER']]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(3))
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $service = new ActivateMembership($memberships, $gateway, $bus, $this->createStub(LoggerInterface::class));
        $service->activate(self::USER_ID, new \DateTimeImmutable('2026-01-01'), 'admin');
    }

    public function testActivateRenewsExistingMembershipUpdatesExpiresAt(): void
    {
        $expiresAt = new \DateTimeImmutable('2027-01-01');
        $existing = Membership::create(self::USER_ID, new \DateTimeImmutable('2026-01-01'), $expiresAt, 'admin', null, null, new \DateTimeImmutable('2026-01-01'));

        $memberships = $this->createMock(MembershipRepositoryInterface::class);
        $memberships->method('findActiveByUserId')->willReturn($existing);
        $memberships->expects($this->once())->method('flush');
        $memberships->expects($this->never())->method('save');

        $gateway = $this->createStub(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => self::DISCORD_ID, 'roles' => ['ROLE_USER', 'ROLE_MEMBER']]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(3))
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $service = new ActivateMembership($memberships, $gateway, $bus, $this->createStub(LoggerInterface::class));
        $service->activate(self::USER_ID, new \DateTimeImmutable('2026-06-01'), 'helloasso');

        // expiresAt should be max(2027-01-01, 2026-06-01) + 12 months = 2028-01-01
        self::assertSame('2028-01-01', $existing->getExpiresAt()->format('Y-m-d'));
    }

    public function testActivateNoDiscordSyncWhenNoDiscordIdButNotificationStillDispatched(): void
    {
        $memberships = $this->createStub(MembershipRepositoryInterface::class);
        $memberships->method('findActiveByUserId')->willReturn(null);

        $gateway = $this->createStub(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => null, 'roles' => ['ROLE_USER']]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(2))
            ->method('dispatch')
            ->with($this->logicalOr(
                $this->isInstanceOf(MembershipActivatedNotificationMessage::class),
                $this->isInstanceOf(SyncMemberToDolibarrMessage::class),
            ))
            ->willReturn(new Envelope(new \stdClass()));

        $service = new ActivateMembership($memberships, $gateway, $bus, $this->createStub(LoggerInterface::class));
        $service->activate(self::USER_ID, new \DateTimeImmutable('2026-01-01'), 'admin');
    }

    public function testActivateLogsErrorWhenDispatchThrows(): void
    {
        $memberships = $this->createStub(MembershipRepositoryInterface::class);
        $memberships->method('findActiveByUserId')->willReturn(null);

        $gateway = $this->createStub(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => self::DISCORD_ID, 'roles' => ['ROLE_USER', 'ROLE_MEMBER']]);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('bus error'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(3))->method('error')
            ->with($this->logicalOr(
                $this->equalTo('membership.discord_sync_dispatch_failed'),
                $this->equalTo('membership.activation_notification_dispatch_failed'),
                $this->equalTo('membership.dolibarr_sync_dispatch_failed'),
            ));

        $service = new ActivateMembership($memberships, $gateway, $bus, $logger);
        $service->activate(self::USER_ID, new \DateTimeImmutable('2026-01-01'), 'admin');
    }
}
