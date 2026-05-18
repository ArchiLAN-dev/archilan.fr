<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Application\Handler\MembershipExpiredNotificationMessageHandler;
use App\Membership\Application\Message\MembershipExpiredNotificationMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;

final class MembershipExpiredNotificationMessageHandlerTest extends TestCase
{
    public function testInvokeSendsEmailWhenUserFound(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $message = new MembershipExpiredNotificationMessage('user-1');

        $handler = $this->createHandler($mailer, $logger, [
            'email' => 'test@example.com',
            'display_name' => 'Test User',
        ]);

        $handler($message);
    }

    public function testInvokeLogsAndRethrowsWhenUserNotFound(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('membership.expired_notification_user_not_found', self::anything());

        $message = new MembershipExpiredNotificationMessage('user-missing');
        $handler = $this->createHandler($mailer, $logger, false);

        $this->expectException(\RuntimeException::class);
        $handler($message);
    }

    public function testInvokeLogsAndRethrowsOnSmtpFailure(): void
    {
        $smtpError = new \RuntimeException('SMTP error');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willThrowException($smtpError);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('membership.expired_notification_send_failed', self::anything());

        $message = new MembershipExpiredNotificationMessage('user-1');
        $handler = $this->createHandler($mailer, $logger, [
            'email' => 'test@example.com',
            'display_name' => 'Test User',
        ]);

        $this->expectException(\RuntimeException::class);
        $handler($message);
    }

    public function testInvokeBuildsFallbackUrlWhenSlugsEmpty(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $logger = $this->createStub(LoggerInterface::class);

        $message = new MembershipExpiredNotificationMessage('user-1');

        $handler = new MembershipExpiredNotificationMessageHandler(
            $this->createConnectionReturning(['email' => 'test@example.com', 'display_name' => 'Test User']),
            $mailer,
            $logger,
            'noreply@archilan.fr',
            'https://archilan.fr',
            '',
            '',
            false,
        );

        $handler($message);
    }

    /**
     * @param array<string, mixed>|false $rowData
     */
    private function createHandler(
        MailerInterface $mailer,
        LoggerInterface $logger,
        array|false $rowData,
    ): MembershipExpiredNotificationMessageHandler {
        return new MembershipExpiredNotificationMessageHandler(
            $this->createConnectionReturning($rowData),
            $mailer,
            $logger,
            'noreply@archilan.fr',
            'https://archilan.fr',
            'archilan',
            'cotisation-2026',
            false,
        );
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
