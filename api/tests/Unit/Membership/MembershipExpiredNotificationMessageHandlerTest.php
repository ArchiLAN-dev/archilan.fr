<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use App\Membership\Application\Handler\MembershipExpiredNotificationMessageHandler;
use App\Membership\Application\Message\MembershipExpiredNotificationMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;

final class MembershipExpiredNotificationMessageHandlerTest extends TestCase
{
    public function testInvokeSendsEmailWhenUserFound(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getEmail')->willReturn('test@example.com');
        $user->method('getDisplayName')->willReturn('Test User');
        $user->method('getDeletedAt')->willReturn(null);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $handler = $this->createHandler($this->stubUsers($user), $mailer, $logger);
        $handler(new MembershipExpiredNotificationMessage('user-1'));
    }

    public function testInvokeLogsAndRethrowsWhenUserNotFound(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('membership.expired_notification_user_not_found', self::anything());

        $handler = $this->createHandler($this->stubUsers(null), $mailer, $logger);

        $this->expectException(\RuntimeException::class);
        $handler(new MembershipExpiredNotificationMessage('user-missing'));
    }

    public function testInvokeLogsAndRethrowsOnSmtpFailure(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getEmail')->willReturn('test@example.com');
        $user->method('getDisplayName')->willReturn('Test User');
        $user->method('getDeletedAt')->willReturn(null);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willThrowException(new \RuntimeException('SMTP error'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('membership.expired_notification_send_failed', self::anything());

        $handler = $this->createHandler($this->stubUsers($user), $mailer, $logger);

        $this->expectException(\RuntimeException::class);
        $handler(new MembershipExpiredNotificationMessage('user-1'));
    }

    public function testInvokeBuildsFallbackUrlWhenSlugsEmpty(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getEmail')->willReturn('test@example.com');
        $user->method('getDisplayName')->willReturn('Test User');
        $user->method('getDeletedAt')->willReturn(null);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $handler = new MembershipExpiredNotificationMessageHandler(
            $this->stubUsers($user),
            $mailer,
            $this->createStub(LoggerInterface::class),
            'noreply@archilan.fr',
            'https://archilan.fr',
            '',
            '',
            false,
        );

        $handler(new MembershipExpiredNotificationMessage('user-1'));
    }

    private function createHandler(
        UserRepositoryInterface $users,
        MailerInterface $mailer,
        LoggerInterface $logger,
    ): MembershipExpiredNotificationMessageHandler {
        return new MembershipExpiredNotificationMessageHandler(
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

    private function stubUsers(?User $user): UserRepositoryInterface
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($user);

        return $users;
    }
}
