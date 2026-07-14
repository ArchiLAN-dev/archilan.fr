<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Application\Handler\StopPersonalRunJobHandler;
use App\PersonalRuns\Application\Message\StopPersonalRunJob;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class StopPersonalRunJobHandlerTest extends TestCase
{
    public function testPersonalRunNotFoundLogsErrorAndReturns(): void
    {
        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('personal_run.stop.not_found', self::arrayHasKey('runId'));

        $runnerGateway = $this->createMock(RunnerGatewayInterface::class);
        $runnerGateway->expects(self::never())->method('stopSession');

        $this->makeHandler(runs: $runs, runnerGateway: $runnerGateway, logger: $logger)(new StopPersonalRunJob('run-missing'));
    }

    public function testPersonalRunHasNoSessionLogsWarningAndReturns(): void
    {
        $now = new \DateTimeImmutable();
        $run = Run::create('owner-1', 'Test Run', $now);

        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')
            ->with('personal_run.stop.no_session', self::arrayHasKey('runId'));

        $runnerGateway = $this->createMock(RunnerGatewayInterface::class);
        $runnerGateway->expects(self::never())->method('stopSession');

        $this->makeHandler(runs: $runs, runnerGateway: $runnerGateway, logger: $logger)(new StopPersonalRunJob($run->getId()));
    }

    public function testSessionNotFoundLogsWarningAndReturns(): void
    {
        $now = new \DateTimeImmutable();
        $run = Run::create('owner-1', 'Test Run', $now);
        $run->attachSession('sess-missing');

        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $sessions = self::createStub(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')
            ->with('personal_run.stop.session_not_found', self::logicalAnd(
                self::arrayHasKey('runId'),
                self::arrayHasKey('sessionId'),
            ));

        $runnerGateway = $this->createMock(RunnerGatewayInterface::class);
        $runnerGateway->expects(self::never())->method('stopSession');

        $this->makeHandler(runs: $runs, sessions: $sessions, runnerGateway: $runnerGateway, logger: $logger)(new StopPersonalRunJob($run->getId()));
    }

    public function testAllFoundCallsStopSession(): void
    {
        $now = new \DateTimeImmutable();
        $run = Run::create('owner-1', 'Test Run', $now);
        $run->attachSession('sess-1');

        $session = Session::create('sess-1', 'event-1', $now);

        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $sessions = self::createStub(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn($session);

        $runnerGateway = $this->createMock(RunnerGatewayInterface::class);
        $runnerGateway->expects(self::once())->method('stopSession')->with('sess-1');

        $this->makeHandler(runs: $runs, sessions: $sessions, runnerGateway: $runnerGateway)(new StopPersonalRunJob($run->getId()));
    }

    private function makeHandler(
        ?RunRepositoryInterface $runs = null,
        ?SessionRepositoryInterface $sessions = null,
        ?RunnerGatewayInterface $runnerGateway = null,
        ?LoggerInterface $logger = null,
    ): StopPersonalRunJobHandler {
        return new StopPersonalRunJobHandler(
            $runs ?? self::createStub(RunRepositoryInterface::class),
            $sessions ?? self::createStub(SessionRepositoryInterface::class),
            $runnerGateway ?? self::createStub(RunnerGatewayInterface::class),
            $logger ?? self::createStub(LoggerInterface::class),
        );
    }
}
