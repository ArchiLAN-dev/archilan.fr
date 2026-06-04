<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Application\Handler\CheckMembershipExpiryMessageHandler;
use App\Membership\Application\MembershipExpiryCheckQueryInterface;
use App\Membership\Application\Message\CheckMembershipExpiryMessage;
use App\Membership\Application\Message\ExpireMembershipMessage;
use App\Membership\Application\Message\MembershipReminderMessage;
use App\Membership\Domain\Membership;
use App\Membership\Domain\MembershipRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class CheckMembershipExpiryMessageHandlerTest extends TestCase
{
    public function testInvokeDispatchesExpireMembershipMessagesForExpiredMemberships(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(2))->method('dispatch')->willReturn(new Envelope(new \stdClass()))
            ->with(self::callback(static fn (object $msg): bool => $msg instanceof ExpireMembershipMessage));

        $expiryCheck = $this->createStub(MembershipExpiryCheckQueryInterface::class);
        $expiryCheck->method('findExpiredActiveIds')->willReturn(['id-1', 'id-2']);
        $expiryCheck->method('findPendingReminderIds')->willReturn([]);

        $handler = new CheckMembershipExpiryMessageHandler(
            $expiryCheck,
            $this->createStub(MembershipRepositoryInterface::class),
            $bus,
        );

        $handler(new CheckMembershipExpiryMessage());
    }

    public function testInvokeWritesReminder30BeforeDispatch(): void
    {
        $membership = Membership::create(
            'user-1',
            new \DateTimeImmutable(),
            new \DateTimeImmutable('+365 days'),
            'helloasso',
            null,
            null,
            new \DateTimeImmutable(),
        );

        $callOrder = [];

        $expiryCheck = $this->createStub(MembershipExpiryCheckQueryInterface::class);
        $expiryCheck->method('findExpiredActiveIds')->willReturn([]);
        $expiryCheck->method('findPendingReminderIds')
            ->willReturnCallback(static function (\DateTimeImmutable $now, int $daysLeft): array {
                return 30 === $daysLeft ? ['id-30'] : [];
            });

        $memberships = $this->createMock(MembershipRepositoryInterface::class);
        $memberships->method('findById')->willReturn($membership);
        $memberships->expects(self::once())->method('flush')->willReturnCallback(static function () use (&$callOrder): void {
            $callOrder[] = 'flush';
        });

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')
            ->with(new MembershipReminderMessage('id-30', 30))
            ->willReturnCallback(static function () use (&$callOrder): Envelope {
                $callOrder[] = 'dispatch';

                return new Envelope(new \stdClass());
            });

        $handler = new CheckMembershipExpiryMessageHandler(
            $expiryCheck,
            $memberships,
            $bus,
        );

        $handler(new CheckMembershipExpiryMessage());

        self::assertSame(['flush', 'dispatch'], $callOrder);
    }

    public function testInvokeWritesReminder7BeforeDispatch(): void
    {
        $membership = Membership::create(
            'user-1',
            new \DateTimeImmutable(),
            new \DateTimeImmutable('+7 days'),
            'helloasso',
            null,
            null,
            new \DateTimeImmutable(),
        );

        $callOrder = [];

        $expiryCheck = $this->createStub(MembershipExpiryCheckQueryInterface::class);
        $expiryCheck->method('findExpiredActiveIds')->willReturn([]);
        $expiryCheck->method('findPendingReminderIds')
            ->willReturnCallback(static function (\DateTimeImmutable $now, int $daysLeft): array {
                return 7 === $daysLeft ? ['id-7'] : [];
            });

        $memberships = $this->createMock(MembershipRepositoryInterface::class);
        $memberships->method('findById')->willReturn($membership);
        $memberships->expects(self::once())->method('flush')->willReturnCallback(static function () use (&$callOrder): void {
            $callOrder[] = 'flush';
        });

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')
            ->with(new MembershipReminderMessage('id-7', 7))
            ->willReturnCallback(static function () use (&$callOrder): Envelope {
                $callOrder[] = 'dispatch';

                return new Envelope(new \stdClass());
            });

        $handler = new CheckMembershipExpiryMessageHandler(
            $expiryCheck,
            $memberships,
            $bus,
        );

        $handler(new CheckMembershipExpiryMessage());

        self::assertSame(['flush', 'dispatch'], $callOrder);
    }

    public function testInvokeDoesNothingWhenNoMembershipsMatch(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $expiryCheck = $this->createStub(MembershipExpiryCheckQueryInterface::class);
        $expiryCheck->method('findExpiredActiveIds')->willReturn([]);
        $expiryCheck->method('findPendingReminderIds')->willReturn([]);

        $memberships = $this->createMock(MembershipRepositoryInterface::class);
        $memberships->expects(self::never())->method('flush');

        $handler = new CheckMembershipExpiryMessageHandler(
            $expiryCheck,
            $memberships,
            $bus,
        );

        $handler(new CheckMembershipExpiryMessage());
    }
}
