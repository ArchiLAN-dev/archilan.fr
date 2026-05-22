<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use App\Membership\Application\Handler\MembershipReminderMessageHandler;
use App\Membership\Application\Message\MembershipReminderMessage;
use App\Membership\Domain\Membership;
use App\Membership\Domain\MembershipRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;

final class MembershipReminderMessageHandlerTest extends TestCase
{
    public function testInvokeSendsEmailFor30DaysReminder(): void
    {
        $membership = $this->makeMembership();

        $user = $this->makeUser();

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $handler = $this->createHandler($membership, $user, $mailer, $logger);
        $handler(new MembershipReminderMessage('membership-1', 30));
    }

    public function testInvokeSendsEmailFor7DaysReminder(): void
    {
        $membership = $this->makeMembership();
        $user = $this->makeUser();

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $handler = $this->createHandler($membership, $user, $mailer, $this->createStub(LoggerInterface::class));
        $handler(new MembershipReminderMessage('membership-1', 7));
    }

    public function testInvokeLogsAndRethrowsWhenMembershipNotFound(): void
    {
        $memberships = $this->createStub(MembershipRepositoryInterface::class);
        $memberships->method('findById')->willReturn(null);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('membership.reminder_notification_not_found', self::anything());

        $handler = new MembershipReminderMessageHandler(
            $memberships,
            $this->createStub(UserRepositoryInterface::class),
            $mailer,
            $logger,
            'noreply@archilan.fr',
            'https://archilan.fr',
            'archilan',
            'cotisation-2026',
            false,
        );

        $this->expectException(\RuntimeException::class);
        $handler(new MembershipReminderMessage('membership-missing', 30));
    }

    public function testInvokeLogsAndRethrowsOnSmtpFailure(): void
    {
        $membership = $this->makeMembership();
        $user = $this->makeUser();

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willThrowException(new \RuntimeException('SMTP error'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('membership.reminder_notification_send_failed', self::anything());

        $handler = $this->createHandler($membership, $user, $mailer, $logger);

        $this->expectException(\RuntimeException::class);
        $handler(new MembershipReminderMessage('membership-1', 30));
    }

    private function makeMembership(): Membership
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return Membership::create('user-1', $now, new \DateTimeImmutable('2027-05-16T00:00:00+00:00'), 'helloasso', null, null, $now);
    }

    private function makeUser(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getEmail')->willReturn('test@example.com');
        $user->method('getDisplayName')->willReturn('Test User');
        $user->method('getDeletedAt')->willReturn(null);

        return $user;
    }

    private function createHandler(
        Membership $membership,
        User $user,
        MailerInterface $mailer,
        LoggerInterface $logger,
    ): MembershipReminderMessageHandler {
        $memberships = $this->createStub(MembershipRepositoryInterface::class);
        $memberships->method('findById')->willReturn($membership);

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($user);

        return new MembershipReminderMessageHandler(
            $memberships,
            $users,
            $mailer,
            $logger,
            'noreply@archilan.fr',
            'https://archilan.fr',
            'archilan',
            'cotisation-2026',
            false,
        );
    }
}
