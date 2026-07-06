<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Membership\Application\Handler\MembershipExpiredNotificationMessageHandler;
use App\Membership\Application\Message\MembershipExpiredNotificationMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;

final class MembershipExpiredNotificationMessageHandlerTest extends TestCase
{
    public function testInvokeSendsEmailWhenUserFound(): void
    {
        $user = self::user();

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
        $user = self::user();

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
        $user = self::user();

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

    private static function user(): User
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');

        return new User('user-1', 'test@example.com', 'test@example.com', 'Test User', 'hash', ['ROLE_USER'], $now, $now, $now);
    }
}
