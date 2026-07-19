<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Application\Command\AdminCreateMembership;
use App\Membership\Application\Port\UserRoleGatewayInterface;
use App\Membership\Domain\Entity\Membership;
use App\Membership\Domain\Repository\MembershipRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;

final class AdminCreateMembershipTest extends TestCase
{
    public function testCreateExpiresExistingActiveMembershipBeforeInsertingNew(): void
    {
        $now = new \DateTimeImmutable();
        $existingMembership = Membership::create('user-id', $now->modify('-1 year'), $now, 'admin', null, null, $now->modify('-1 year'));

        $memberships = $this->createMock(MembershipRepositoryInterface::class);
        $memberships->method('findActiveByUserId')->willReturn($existingMembership);
        $memberships->expects(self::once())->method('flush');
        $memberships->expects(self::once())->method('save');

        $gateway = self::createStub(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => null, 'roles' => []]);

        $service = new AdminCreateMembership(
            $memberships,
            $gateway,
            self::createStub(MessageBusInterface::class),
            self::createStub(LoggerInterface::class),
            new MockClock(),
        );
        $service->create('user-id', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-01'), null);

        self::assertSame('expired', $existingMembership->getStatus());
    }

    public function testCreateWithNoExistingMembershipSavesOnce(): void
    {
        $memberships = $this->createMock(MembershipRepositoryInterface::class);
        $memberships->method('findActiveByUserId')->willReturn(null);
        $memberships->expects(self::never())->method('flush');
        $memberships->expects(self::once())->method('save');

        $gateway = self::createStub(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => null, 'roles' => []]);

        $service = new AdminCreateMembership(
            $memberships,
            $gateway,
            self::createStub(MessageBusInterface::class),
            self::createStub(LoggerInterface::class),
            new MockClock(),
        );
        $service->create('user-id', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-01'), null);
    }

    public function testCreateReturnsEntityDataWithActiveStatus(): void
    {
        $memberships = self::createStub(MembershipRepositoryInterface::class);
        $memberships->method('findActiveByUserId')->willReturn(null);

        $gateway = self::createStub(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => null, 'roles' => []]);

        $service = new AdminCreateMembership(
            $memberships,
            $gateway,
            self::createStub(MessageBusInterface::class),
            self::createStub(LoggerInterface::class),
            new MockClock(),
        );
        $result = $service->create('user-id', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-01'), 'note');

        self::assertSame('active', $result->status);
        self::assertSame('user-id', $result->userId);
        self::assertSame('admin', $result->source);
        self::assertSame('note', $result->adminNote);
        self::assertStringStartsWith('2026-01-01', $result->startedAt);
        self::assertStringStartsWith('2027-01-01', $result->expiresAt);
    }
}
