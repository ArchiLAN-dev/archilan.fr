<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use App\Membership\Application\ActivateMembershipInterface;
use App\Membership\Application\Message\MembershipPaymentUnmatchedMessage;
use App\Membership\Application\ProcessHelloAssoMembershipPayment;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ProcessHelloAssoMembershipPaymentTest extends TestCase
{
    public function testProcessSkipsWhenPayerEmailIsNull(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'membership.helloasso_payment_skipped_no_email',
            self::anything()
        );

        $service = new ProcessHelloAssoMembershipPayment(
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(ActivateMembershipInterface::class),
            $this->createStub(MessageBusInterface::class),
            $logger,
        );

        $service->process('order-1', null, new \DateTimeImmutable());
    }

    public function testProcessSkipsWhenPaidAtIsNull(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'membership.helloasso_payment_skipped_no_paid_at',
            self::anything()
        );

        $service = new ProcessHelloAssoMembershipPayment(
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(ActivateMembershipInterface::class),
            $this->createStub(MessageBusInterface::class),
            $logger,
        );

        $service->process('order-1', 'payer@example.org', null);
    }

    public function testProcessSkipsWhenUserNotFoundAndDispatchesUnmatchedMessage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'membership.helloasso_payment_user_not_found',
            self::anything()
        );

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByEmailCanonical')->willReturn(null);

        $activateMembership = $this->createMock(ActivateMembershipInterface::class);
        $activateMembership->expects(self::never())->method('activate');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $msg): bool => $msg instanceof MembershipPaymentUnmatchedMessage
                && 'unknown@example.org' === $msg->payerEmail
                && 'order-1' === $msg->helloassoOrderId))
            ->willReturn(new Envelope(new \stdClass()));

        $service = new ProcessHelloAssoMembershipPayment(
            $users,
            $activateMembership,
            $bus,
            $logger,
        );

        $service->process('order-1', 'unknown@example.org', new \DateTimeImmutable());
    }

    public function testProcessActivatesMembershipOnSuccess(): void
    {
        $paidAt = new \DateTimeImmutable('2026-05-16T10:00:00+00:00');

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn('user-123');
        $user->method('getDeletedAt')->willReturn(null);

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByEmailCanonical')->willReturn($user);

        $activateMembership = $this->createMock(ActivateMembershipInterface::class);
        $activateMembership->expects(self::once())->method('activate')->with(
            'user-123',
            $paidAt,
            'helloasso',
            'order-1',
        );

        $service = new ProcessHelloAssoMembershipPayment(
            $users,
            $activateMembership,
            $this->createStub(MessageBusInterface::class),
            $this->createStub(LoggerInterface::class),
        );

        $service->process('order-1', 'payer@example.org', $paidAt);
    }

    public function testProcessLogsAlreadyProcessedOnDuplicate(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn('user-123');
        $user->method('getDeletedAt')->willReturn(null);

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByEmailCanonical')->willReturn($user);

        $driverEx = $this->createStub(\Doctrine\DBAL\Driver\Exception::class);
        $exception = new UniqueConstraintViolationException($driverEx, null);

        $activateMembership = $this->createStub(ActivateMembershipInterface::class);
        $activateMembership->method('activate')->willThrowException($exception);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'membership.already_processed',
            self::anything()
        );

        $service = new ProcessHelloAssoMembershipPayment(
            $users,
            $activateMembership,
            $this->createStub(MessageBusInterface::class),
            $logger,
        );

        $service->process('order-1', 'payer@example.org', new \DateTimeImmutable());
    }
}
