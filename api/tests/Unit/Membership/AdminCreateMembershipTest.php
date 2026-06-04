<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Application\AdminCreateMembership;
use App\Membership\Application\UserRoleGatewayInterface;
use App\Membership\Domain\Membership;
use App\Membership\Domain\MembershipRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class AdminCreateMembershipTest extends TestCase
{
    public function testCreateExpiresExistingActiveMembershipBeforeInsertingNew(): void
    {
        $now = new \DateTimeImmutable();
        $existingMembership = Membership::create('user-id', $now->modify('-1 year'), $now, 'admin', null, null, $now->modify('-1 year'));

        $memberships = $this->createMock(MembershipRepositoryInterface::class);
        $memberships->method('findActiveByUserId')->willReturn($existingMembership);
        $memberships->expects($this->once())->method('flush');
        $memberships->expects($this->once())->method('save');

        $gateway = $this->createStub(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => null, 'roles' => []]);

        $service = new AdminCreateMembership(
            $memberships,
            $gateway,
            $this->createStub(MessageBusInterface::class),
            $this->createStub(LoggerInterface::class),
        );
        $service->create('user-id', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-01'), null);

        self::assertSame('expired', $existingMembership->getStatus());
    }

    public function testCreateWithNoExistingMembershipSavesOnce(): void
    {
        $memberships = $this->createMock(MembershipRepositoryInterface::class);
        $memberships->method('findActiveByUserId')->willReturn(null);
        $memberships->expects($this->never())->method('flush');
        $memberships->expects($this->once())->method('save');

        $gateway = $this->createStub(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => null, 'roles' => []]);

        $service = new AdminCreateMembership(
            $memberships,
            $gateway,
            $this->createStub(MessageBusInterface::class),
            $this->createStub(LoggerInterface::class),
        );
        $service->create('user-id', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-01'), null);
    }

    public function testCreateReturnsEntityDataWithActiveStatus(): void
    {
        $memberships = $this->createStub(MembershipRepositoryInterface::class);
        $memberships->method('findActiveByUserId')->willReturn(null);

        $gateway = $this->createStub(UserRoleGatewayInterface::class);
        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => null, 'roles' => []]);

        $service = new AdminCreateMembership(
            $memberships,
            $gateway,
            $this->createStub(MessageBusInterface::class),
            $this->createStub(LoggerInterface::class),
        );
        $result = $service->create('user-id', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-01'), 'note');

        self::assertSame('active', $result['status']);
        self::assertSame('user-id', $result['userId']);
        self::assertSame('admin', $result['source']);
        self::assertSame('note', $result['adminNote']);
        self::assertStringStartsWith('2026-01-01', $result['startedAt']);
        self::assertStringStartsWith('2027-01-01', $result['expiresAt']);
    }
}
