<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Application\Handler\MembershipActivatedNotificationMessageHandler;
use App\Membership\Application\Message\MembershipActivatedNotificationMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class MembershipActivatedNotificationMessageHandlerTest extends TestCase
{
    public function testInvokeSendsEmailWhenUserFound(): void
    {
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

        $expiresAt = new \DateTimeImmutable('2027-05-16');
        $message = new MembershipActivatedNotificationMessage('user-1', $expiresAt);

        $handler = new MembershipActivatedNotificationMessageHandler(
            $this->createConnectionReturning(['email' => 'test@example.com', 'display_name' => 'Test User']),
            $mailer,
            $logger,
            'noreply@archilan.fr',
            'https://archilan.fr',
        );

        $handler($message);
    }

    public function testInvokeLogsAndRethrowsWhenUserNotFound(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('membership.activated_notification_user_not_found', self::anything());

        $message = new MembershipActivatedNotificationMessage('user-missing', new \DateTimeImmutable());

        $handler = new MembershipActivatedNotificationMessageHandler(
            $this->createConnectionReturning(false),
            $mailer,
            $logger,
            'noreply@archilan.fr',
            'https://archilan.fr',
        );

        $this->expectException(\RuntimeException::class);
        $handler($message);
    }

    public function testInvokeLogsAndRethrowsOnSmtpFailure(): void
    {
        $smtpError = new \RuntimeException('SMTP connection failed');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willThrowException($smtpError);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('membership.activated_notification_send_failed', self::anything());

        $message = new MembershipActivatedNotificationMessage('user-1', new \DateTimeImmutable());

        $handler = new MembershipActivatedNotificationMessageHandler(
            $this->createConnectionReturning(['email' => 'test@example.com', 'display_name' => 'Test User']),
            $mailer,
            $logger,
            'noreply@archilan.fr',
            'https://archilan.fr',
        );

        $this->expectException(\RuntimeException::class);
        $handler($message);
    }

    public function testInvokeWorksWithNullDisplayName(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $logger = $this->createStub(LoggerInterface::class);

        $message = new MembershipActivatedNotificationMessage('user-1', new \DateTimeImmutable('2027-05-16'));

        $handler = new MembershipActivatedNotificationMessageHandler(
            $this->createConnectionReturning(['email' => 'test@example.com', 'display_name' => null]),
            $mailer,
            $logger,
            'noreply@archilan.fr',
            'https://archilan.fr',
        );

        $handler($message);
    }

    /**
     * @param array<string, mixed>|false $rowData
     */
    private function createConnectionReturning(array|false $rowData): Connection
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($rowData);

        $expr = $this->createStub(ExpressionBuilder::class);

        $qb = $this->createStub(QueryBuilder::class);
        $qb->method('select')->willReturn($qb);
        $qb->method('from')->willReturn($qb);
        $qb->method('where')->willReturn($qb);
        $qb->method('andWhere')->willReturn($qb);
        $qb->method('setParameter')->willReturn($qb);
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($result);

        $connection = $this->createStub(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($qb);
        $connection->method('quoteSingleIdentifier')->willReturn('"user"');

        return $connection;
    }
}
