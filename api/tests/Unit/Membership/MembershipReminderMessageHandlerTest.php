<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Application\Handler\MembershipReminderMessageHandler;
use App\Membership\Application\Message\MembershipReminderMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;

final class MembershipReminderMessageHandlerTest extends TestCase
{
    public function testInvokeSendsEmailFor30DaysReminder(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $message = new MembershipReminderMessage('membership-1', 30);

        $handler = $this->createHandler($mailer, $logger, [
            'email' => 'test@example.com',
            'display_name' => 'Test User',
            'expires_at' => '2027-05-16T00:00:00+00:00',
        ]);

        $handler($message);
    }

    public function testInvokeSendsEmailFor7DaysReminder(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $logger = $this->createStub(LoggerInterface::class);

        $message = new MembershipReminderMessage('membership-1', 7);

        $handler = $this->createHandler($mailer, $logger, [
            'email' => 'test@example.com',
            'display_name' => 'Test User',
            'expires_at' => '2027-05-16T00:00:00+00:00',
        ]);

        $handler($message);
    }

    public function testInvokeLogsAndRethrowsWhenMembershipNotFound(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('membership.reminder_notification_not_found', self::anything());

        $message = new MembershipReminderMessage('membership-missing', 30);
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
            ->with('membership.reminder_notification_send_failed', self::anything());

        $message = new MembershipReminderMessage('membership-1', 30);
        $handler = $this->createHandler($mailer, $logger, [
            'email' => 'test@example.com',
            'display_name' => 'Test User',
            'expires_at' => '2027-05-16T00:00:00+00:00',
        ]);

        $this->expectException(\RuntimeException::class);
        $handler($message);
    }

    /**
     * @param array<string, mixed>|false $rowData
     */
    private function createHandler(
        MailerInterface $mailer,
        LoggerInterface $logger,
        array|false $rowData,
    ): MembershipReminderMessageHandler {
        return new MembershipReminderMessageHandler(
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
        $qb->method('innerJoin')->willReturn($qb);
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
