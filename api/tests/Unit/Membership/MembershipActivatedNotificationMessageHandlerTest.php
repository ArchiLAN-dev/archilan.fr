<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Membership\Application\Handler\MembershipActivatedNotificationMessageHandler;
use App\Membership\Application\Message\MembershipActivatedNotificationMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class MembershipActivatedNotificationMessageHandlerTest extends TestCase
{
    public function testInvokeSendsEmailWhenUserFound(): void
    {
        $user = self::user();

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($user);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')
            ->with(self::callback(static function (Email $email): bool {
                $html = $email->getHtmlBody();
                $text = $email->getTextBody();

                return is_string($html)
                    && is_string($text)
                    && str_contains($html, 'https://archilan.fr/compte')
                    && str_contains($text, 'https://archilan.fr/compte');
            }));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $handler = new MembershipActivatedNotificationMessageHandler(
            $users,
            $mailer,
            $logger,
            'noreply@archilan.fr',
            'https://archilan.fr',
        );

        $handler(new MembershipActivatedNotificationMessage('user-1', new \DateTimeImmutable('2027-05-16')));
    }

    public function testInvokeLogsAndRethrowsWhenUserNotFound(): void
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn(null);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('membership.activated_notification_user_not_found', self::anything());

        $handler = new MembershipActivatedNotificationMessageHandler(
            $users,
            $mailer,
            $logger,
            'noreply@archilan.fr',
            'https://archilan.fr',
        );

        $this->expectException(\RuntimeException::class);
        $handler(new MembershipActivatedNotificationMessage('user-missing', new \DateTimeImmutable()));
    }

    public function testInvokeLogsAndRethrowsOnSmtpFailure(): void
    {
        $user = self::user();

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($user);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willThrowException(new \RuntimeException('SMTP connection failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('membership.activated_notification_send_failed', self::anything());

        $handler = new MembershipActivatedNotificationMessageHandler(
            $users,
            $mailer,
            $logger,
            'noreply@archilan.fr',
            'https://archilan.fr',
        );

        $this->expectException(\RuntimeException::class);
        $handler(new MembershipActivatedNotificationMessage('user-1', new \DateTimeImmutable()));
    }

    public function testInvokeWorksWithEmptyDisplayName(): void
    {
        $user = self::user('');

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($user);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $handler = new MembershipActivatedNotificationMessageHandler(
            $users,
            $mailer,
            $this->createStub(LoggerInterface::class),
            'noreply@archilan.fr',
            'https://archilan.fr',
        );

        $handler(new MembershipActivatedNotificationMessage('user-1', new \DateTimeImmutable('2027-05-16')));
    }

    private static function user(string $displayName = 'Test User'): User
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');

        return new User('user-1', 'test@example.com', 'test@example.com', $displayName, 'hash', ['ROLE_USER'], $now, $now, $now);
    }
}
