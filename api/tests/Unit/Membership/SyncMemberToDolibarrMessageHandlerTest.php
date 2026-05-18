<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Application\DolibarrClientInterface;
use App\Membership\Application\Handler\SyncMemberToDolibarrMessageHandler;
use App\Membership\Application\Message\SyncMemberToDolibarrMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SyncMemberToDolibarrMessageHandlerTest extends TestCase
{
    public function testHandleSyncsToDolibarrWhenMembershipFound(): void
    {
        $row = [
            'email' => 'jean@example.org',
            'display_name' => 'Jean',
            'status' => 'active',
            'expires_at' => '2027-05-16 10:00:00+00',
        ];

        $dolibarr = $this->createMock(DolibarrClientInterface::class);
        $dolibarr->expects(self::once())->method('upsertMember')->with(
            'jean@example.org',
            'Jean',
            'active',
            self::isInstanceOf(\DateTimeImmutable::class),
        );

        $handler = new SyncMemberToDolibarrMessageHandler(
            $this->createConnectionReturning($row),
            $dolibarr,
            $this->createStub(LoggerInterface::class),
        );

        $handler(new SyncMemberToDolibarrMessage('membership-id-1'));
    }

    public function testHandleReturnsEarlyWhenMembershipNotFound(): void
    {
        $dolibarr = $this->createMock(DolibarrClientInterface::class);
        $dolibarr->expects(self::never())->method('upsertMember');

        $handler = new SyncMemberToDolibarrMessageHandler(
            $this->createConnectionReturning(false),
            $dolibarr,
            $this->createStub(LoggerInterface::class),
        );

        $handler(new SyncMemberToDolibarrMessage('missing-id'));
    }

    public function testHandleRethrowsOnDolibarrFailure(): void
    {
        $row = [
            'email' => 'jean@example.org',
            'display_name' => 'Jean',
            'status' => 'active',
            'expires_at' => '2027-05-16 10:00:00+00',
        ];

        $dolibarr = $this->createMock(DolibarrClientInterface::class);
        $dolibarr->expects(self::once())->method('upsertMember')
            ->willThrowException(new \RuntimeException('Dolibarr unreachable'));

        $handler = new SyncMemberToDolibarrMessageHandler(
            $this->createConnectionReturning($row),
            $dolibarr,
            $this->createStub(LoggerInterface::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dolibarr unreachable');

        $handler(new SyncMemberToDolibarrMessage('membership-id-1'));
    }

    public function testHandleSyncsWithNullExpiresAtWhenEmptyDate(): void
    {
        $row = [
            'email' => 'jean@example.org',
            'display_name' => 'Jean',
            'status' => 'expired',
            'expires_at' => '',
        ];

        $dolibarr = $this->createMock(DolibarrClientInterface::class);
        $dolibarr->expects(self::once())->method('upsertMember')->with(
            'jean@example.org',
            'Jean',
            'expired',
            null,
        );

        $handler = new SyncMemberToDolibarrMessageHandler(
            $this->createConnectionReturning($row),
            $dolibarr,
            $this->createStub(LoggerInterface::class),
        );

        $handler(new SyncMemberToDolibarrMessage('membership-id-2'));
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
