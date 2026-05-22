<?php

declare(strict_types=1);

namespace App\Tests\Unit\WeeklyRuns;

use App\WeeklyRuns\Application\Handler\StopWeeklyRunsMessageHandler;
use App\WeeklyRuns\Application\Message\StopWeeklyRunsMessage;
use App\WeeklyRuns\Application\WeeklyRunnerGatewayInterface;
use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyEntryRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyRunRepositoryInterface;
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

        $runs = $this->createMock(WeeklyRunRepositoryInterface::class);
        $runs->method('findAllActive')->willReturn([$run]);
        $runs->expects(self::once())->method('flush');

        $entry1 = $this->makeEntry('entry-1', 'run-1', 'session-1');
        $entry2 = $this->makeEntry('entry-2', 'run-1', 'session-2');

        $entries = $this->createStub(WeeklyEntryRepositoryInterface::class);
        $entries->method('findActiveEntriesForRun')->willReturn([$entry1, $entry2]);

        /** @var list<string> $terminateCalls */
        $terminateCalls = [];
        $gateway = $this->createStub(WeeklyRunnerGatewayInterface::class);
        $gateway->method('terminate')->willReturnCallback(static function (string $sessionId) use (&$terminateCalls): void {
            $terminateCalls[] = $sessionId;
            if ('session-1' === $sessionId) {
                throw new \RuntimeException('terminate failed');
            }
        });

        $this->makeHandler($runs, $entries, $gateway)
            ->__invoke(new StopWeeklyRunsMessage());

        self::assertSame(['session-1', 'session-2'], $terminateCalls);
        self::assertSame(WeeklyRun::STATUS_FINISHED, $run->getStatus());
    }

    public function testInvokeFinishesRunEvenWhenAllTerminateFail(): void
    {
        $run = $this->makeRun('run-1');

        $runs = $this->createMock(WeeklyRunRepositoryInterface::class);
        $runs->method('findAllActive')->willReturn([$run]);
        $runs->expects(self::once())->method('flush');

        $entry1 = $this->makeEntry('entry-1', 'run-1', 'session-1');

        $entries = $this->createStub(WeeklyEntryRepositoryInterface::class);
        $entries->method('findActiveEntriesForRun')->willReturn([$entry1]);

        $gateway = $this->createStub(WeeklyRunnerGatewayInterface::class);
        $gateway->method('terminate')->willThrowException(new \RuntimeException('all fail'));

        $this->makeHandler($runs, $entries, $gateway)
            ->__invoke(new StopWeeklyRunsMessage());

        self::assertSame(WeeklyRun::STATUS_FINISHED, $run->getStatus());
    }

    public function testInvokeDoesNothingWhenNoActiveRuns(): void
    {
        $runs = $this->createMock(WeeklyRunRepositoryInterface::class);
        $runs->method('findAllActive')->willReturn([]);
        $runs->expects(self::never())->method('flush');

        $gateway = $this->createMock(WeeklyRunnerGatewayInterface::class);
        $gateway->expects(self::never())->method('terminate');

        $this->makeHandler($runs, $this->createStub(WeeklyEntryRepositoryInterface::class), $gateway)
            ->__invoke(new StopWeeklyRunsMessage());
    }

    public function testInvokeSkipsEntriesWithNullSessionId(): void
    {
        $run = $this->makeRun('run-1');

        $runs = $this->createMock(WeeklyRunRepositoryInterface::class);
        $runs->method('findAllActive')->willReturn([$run]);
        $runs->expects(self::once())->method('flush');

        $entry = $this->makeEntry('entry-1', 'run-1', null);

        $entries = $this->createStub(WeeklyEntryRepositoryInterface::class);
        $entries->method('findActiveEntriesForRun')->willReturn([$entry]);

        $gateway = $this->createMock(WeeklyRunnerGatewayInterface::class);
        $gateway->expects(self::never())->method('terminate');

        $this->makeHandler($runs, $entries, $gateway)
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

    private function makeEntry(string $id, string $runId, ?string $externalSessionId): WeeklyEntry
    {
        return new WeeklyEntry(
            id: $id,
            weeklyRunId: $runId,
            userId: 'user-1',
            attemptNumber: 1,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            externalSessionId: $externalSessionId,
        );
    }

    private function makeHandler(
        WeeklyRunRepositoryInterface $runs,
        WeeklyEntryRepositoryInterface $entries,
        WeeklyRunnerGatewayInterface $gateway,
    ): StopWeeklyRunsMessageHandler {
        return new StopWeeklyRunsMessageHandler(
            $runs,
            $entries,
            $gateway,
            new NullLogger(),
            self::$clock,
        );
    }
}
