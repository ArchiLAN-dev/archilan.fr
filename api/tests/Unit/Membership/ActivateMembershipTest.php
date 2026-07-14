<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Application\Command\ActivateMembership;
use App\Membership\Application\Message\MembershipActivatedNotificationMessage;
use App\Membership\Application\Message\SyncMemberToDolibarrMessage;
use App\Membership\Application\Port\UserRoleGatewayInterface;
use App\Membership\Domain\Entity\Membership;
use App\Membership\Domain\Repository\MembershipRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ActivateMembershipTest extends TestCase
{
    private const string USER_ID = 'user-abc123';
    private const string DISCORD_ID = 'discord-xyz';

    public function testActivateCreatesNewMembershipAndDispatchesSync(): void
    {
        $memberships = $this->createMock(MembershipRepositoryInterface::class);
        $memberships->method('findActiveByUserId')->willReturn(null);
        $memberships->expects(self::once())->method('save')->with(self::isInstanceOf(Membership::class));

        $gateway = self::createStub(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => self::DISCORD_ID, 'roles' => ['ROLE_USER', 'ROLE_MEMBER']]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $service = new ActivateMembership($memberships, $gateway, $bus, self::createStub(LoggerInterface::class), new MockClock());
        $service->activate(self::USER_ID, new \DateTimeImmutable('2026-01-01'), 'admin');
    }

    public function testActivateRenewsExistingMembershipUpdatesExpiresAt(): void
    {
        $expiresAt = new \DateTimeImmutable('2027-01-01');
        $existing = Membership::create(self::USER_ID, new \DateTimeImmutable('2026-01-01'), $expiresAt, 'admin', null, null, new \DateTimeImmutable('2026-01-01'));

        $memberships = $this->createMock(MembershipRepositoryInterface::class);
        $memberships->method('findActiveByUserId')->willReturn($existing);
        $memberships->expects(self::once())->method('flush');
        $memberships->expects(self::never())->method('save');

        $gateway = self::createStub(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => self::DISCORD_ID, 'roles' => ['ROLE_USER', 'ROLE_MEMBER']]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $service = new ActivateMembership($memberships, $gateway, $bus, self::createStub(LoggerInterface::class), new MockClock());
        $service->activate(self::USER_ID, new \DateTimeImmutable('2026-06-01'), 'helloasso');

        // expiresAt should be max(2027-01-01, 2026-06-01) + 12 months = 2028-01-01
        self::assertSame('2028-01-01', $existing->getExpiresAt()->format('Y-m-d'));
    }

    public function testActivateNoDiscordSyncWhenNoDiscordIdButNotificationStillDispatched(): void
    {
        $memberships = self::createStub(MembershipRepositoryInterface::class);
        $memberships->method('findActiveByUserId')->willReturn(null);

        $gateway = self::createStub(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => null, 'roles' => ['ROLE_USER']]);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(2))
            ->method('dispatch')
            ->with(self::logicalOr(
                self::isInstanceOf(MembershipActivatedNotificationMessage::class),
                self::isInstanceOf(SyncMemberToDolibarrMessage::class),
            ))
            ->willReturn(new Envelope(new \stdClass()));

        $service = new ActivateMembership($memberships, $gateway, $bus, self::createStub(LoggerInterface::class), new MockClock());
        $service->activate(self::USER_ID, new \DateTimeImmutable('2026-01-01'), 'admin');
    }

    public function testActivateLogsErrorWhenDispatchThrows(): void
    {
        $memberships = self::createStub(MembershipRepositoryInterface::class);
        $memberships->method('findActiveByUserId')->willReturn(null);

        $gateway = self::createStub(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => self::DISCORD_ID, 'roles' => ['ROLE_USER', 'ROLE_MEMBER']]);

        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('bus error'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(3))->method('error')
            ->with(self::logicalOr(
                self::equalTo('membership.discord_sync_dispatch_failed'),
                self::equalTo('membership.activation_notification_dispatch_failed'),
                self::equalTo('membership.dolibarr_sync_dispatch_failed'),
            ));

        $service = new ActivateMembership($memberships, $gateway, $bus, $logger, new MockClock());
        $service->activate(self::USER_ID, new \DateTimeImmutable('2026-01-01'), 'admin');
    }
}
