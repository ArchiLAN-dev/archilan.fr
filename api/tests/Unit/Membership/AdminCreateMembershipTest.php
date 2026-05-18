<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Application\AdminCreateMembership;
use App\Membership\Application\UserRoleGatewayInterface;
use App\Membership\Domain\Membership;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class AdminCreateMembershipTest extends TestCase
{
    public function testCreateExpiresExistingActiveMembershipBeforeInsertingNew(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $gateway = $this->createStub(UserRoleGatewayInterface::class);
        $bus = $this->createStub(MessageBusInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => null, 'roles' => []]);

        $now = new \DateTimeImmutable();
        $existingMembership = Membership::create('user-id', $now->modify('-1 year'), $now, 'admin', null, null, $now->modify('-1 year'));

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($existingMembership);

        $em->method('getRepository')->willReturn($repo);
        $em->expects($this->exactly(2))->method('flush');
        $em->expects($this->once())->method('persist');

        $service = new AdminCreateMembership($em, $gateway, $bus, $logger);
        $service->create('user-id', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-01'), null);

        self::assertSame('expired', $existingMembership->getStatus());
    }

    public function testCreateWithNoExistingMembershipFlushesOnce(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $gateway = $this->createStub(UserRoleGatewayInterface::class);
        $bus = $this->createStub(MessageBusInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => null, 'roles' => []]);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $em->method('getRepository')->willReturn($repo);
        $em->expects($this->once())->method('flush');
        $em->expects($this->once())->method('persist');

        $service = new AdminCreateMembership($em, $gateway, $bus, $logger);
        $service->create('user-id', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-01'), null);
    }

    public function testCreateReturnsEntityDataWithActiveStatus(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $gateway = $this->createStub(UserRoleGatewayInterface::class);
        $bus = $this->createStub(MessageBusInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $gateway->method('getUserDiscordInfo')->willReturn(['discordId' => null, 'roles' => []]);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $em->method('getRepository')->willReturn($repo);

        $service = new AdminCreateMembership($em, $gateway, $bus, $logger);
        $result = $service->create('user-id', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-01'), 'note');

        self::assertSame('active', $result['status']);
        self::assertSame('user-id', $result['userId']);
        self::assertSame('admin', $result['source']);
        self::assertSame('note', $result['adminNote']);
        self::assertStringStartsWith('2026-01-01', $result['startedAt']);
        self::assertStringStartsWith('2027-01-01', $result['expiresAt']);
    }
}
