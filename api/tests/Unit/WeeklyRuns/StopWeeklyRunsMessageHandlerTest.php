<?php

declare(strict_types=1);

namespace App\Tests\Unit\WeeklyRuns;

use App\WeeklyRuns\Application\Handler\StopWeeklyRunsMessageHandler;
use App\WeeklyRuns\Application\Message\StopWeeklyRunsMessage;
use App\WeeklyRuns\Application\WeeklyRunnerGatewayInterface;
use App\WeeklyRuns\Domain\WeeklyRun;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class StopWeeklyRunsMessageHandlerTest extends TestCase
{
    private static MockClock $clock;

    public static function setUpBeforeClass(): void
    {
        self::$clock = new MockClock(new \DateTimeImmutable('2026-05-18T23:59:00', new \DateTimeZone('UTC')));
    }

    public function testInvokeContinuesAfterTerminateError(): void
    {
        $run = $this->makeRun('run-1');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($run);
        $em->expects(self::once())->method('flush');

        /** @var list<string> $terminateCalls */
        $terminateCalls = [];
        $gateway = $this->createStub(WeeklyRunnerGatewayInterface::class);
        $gateway->method('terminate')->willReturnCallback(static function (string $sessionId) use (&$terminateCalls): void {
            $terminateCalls[] = $sessionId;
            if ('session-1' === $sessionId) {
                throw new \RuntimeException('terminate failed');
            }
        });

        $entryRows = [
            ['id' => 'entry-1', 'external_session_id' => 'session-1'],
            ['id' => 'entry-2', 'external_session_id' => 'session-2'],
        ];

        $this->makeHandler($this->createConnectionReturning(['run-1'], $entryRows), $em, $gateway)
            ->__invoke(new StopWeeklyRunsMessage());

        self::assertSame(['session-1', 'session-2'], $terminateCalls);
        self::assertSame(WeeklyRun::STATUS_FINISHED, $run->getStatus());
    }

    public function testInvokeFinishesRunEvenWhenAllTerminateFail(): void
    {
        $run = $this->makeRun('run-1');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($run);
        $em->expects(self::once())->method('flush');

        $gateway = $this->createStub(WeeklyRunnerGatewayInterface::class);
        $gateway->method('terminate')->willThrowException(new \RuntimeException('all fail'));

        $entryRows = [
            ['id' => 'entry-1', 'external_session_id' => 'session-1'],
        ];

        $this->makeHandler($this->createConnectionReturning(['run-1'], $entryRows), $em, $gateway)
            ->__invoke(new StopWeeklyRunsMessage());

        self::assertSame(WeeklyRun::STATUS_FINISHED, $run->getStatus());
    }

    public function testInvokeSkipsRunWhenEntityNotFound(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $em->expects(self::never())->method('flush');

        $gateway = $this->createMock(WeeklyRunnerGatewayInterface::class);
        $gateway->expects(self::never())->method('terminate');

        $this->makeHandler($this->createConnectionReturning(['run-ghost'], []), $em, $gateway)
            ->__invoke(new StopWeeklyRunsMessage());
    }

    public function testInvokeSkipsEntriesWithNullSessionId(): void
    {
        $run = $this->makeRun('run-1');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($run);
        $em->expects(self::once())->method('flush');

        $gateway = $this->createMock(WeeklyRunnerGatewayInterface::class);
        $gateway->expects(self::never())->method('terminate');

        $entryRows = [
            ['id' => 'entry-1', 'external_session_id' => null],
        ];

        $this->makeHandler($this->createConnectionReturning(['run-1'], $entryRows), $em, $gateway)
            ->__invoke(new StopWeeklyRunsMessage());
    }

    private function makeRun(string $id): WeeklyRun
    {
        return new WeeklyRun(
            id: $id,
            templateId: 'template-1',
            weekYear: 2026,
            weekNumber: 20,
            seed: 'archilan-weekly-2026-20',
            status: WeeklyRun::STATUS_ACTIVE,
            startedAt: new \DateTimeImmutable(),
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function makeHandler(
        Connection $connection,
        EntityManagerInterface $em,
        WeeklyRunnerGatewayInterface $gateway,
    ): StopWeeklyRunsMessageHandler {
        return new StopWeeklyRunsMessageHandler(
            $connection,
            $em,
            $gateway,
            new NullLogger(),
            self::$clock,
        );
    }

    /**
     * @param list<mixed>                $runIds
     * @param list<array<string, mixed>> $entryRows
     */
    private function createConnectionReturning(array $runIds, array $entryRows): Connection
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchFirstColumn')->willReturn($runIds);
        $result->method('fetchAllAssociative')->willReturn($entryRows);

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

        return $connection;
    }
}
