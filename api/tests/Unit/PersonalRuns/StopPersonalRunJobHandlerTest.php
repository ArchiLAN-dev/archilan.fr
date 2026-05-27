<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Application\Handler\StopPersonalRunJobHandler;
use App\PersonalRuns\Application\Message\StopPersonalRunJob;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\Sessions\Infrastructure\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class StopPersonalRunJobHandlerTest extends TestCase
{
    public function testPersonalRunNotFoundLogsErrorAndReturns(): void
    {
        $runs = $this->createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('personal_run.stop.not_found', $this->arrayHasKey('runId'));

        $runnerGateway = $this->createMock(RunnerGatewayInterface::class);
        $runnerGateway->expects($this->never())->method('stopSession');

        $this->makeHandler(runs: $runs, runnerGateway: $runnerGateway, logger: $logger)(new StopPersonalRunJob('run-missing'));
    }

    public function testPersonalRunHasNoSessionLogsWarningAndReturns(): void
    {
        $now = new \DateTimeImmutable();
        $run = Run::create('owner-1', 'Test Run', $now);

        $runs = $this->createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('personal_run.stop.no_session', $this->arrayHasKey('runId'));

        $runnerGateway = $this->createMock(RunnerGatewayInterface::class);
        $runnerGateway->expects($this->never())->method('stopSession');

        $this->makeHandler(runs: $runs, runnerGateway: $runnerGateway, logger: $logger)(new StopPersonalRunJob($run->getId()));
    }

    public function testSessionNotFoundLogsWarningAndReturns(): void
    {
        $now = new \DateTimeImmutable();
        $run = Run::create('owner-1', 'Test Run', $now);
        $run->setSessionId('sess-missing');

        $runs = $this->createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $sessions = $this->createStub(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('personal_run.stop.session_not_found', $this->logicalAnd(
                $this->arrayHasKey('runId'),
                $this->arrayHasKey('sessionId'),
            ));

        $runnerGateway = $this->createMock(RunnerGatewayInterface::class);
        $runnerGateway->expects($this->never())->method('stopSession');

        $this->makeHandler(runs: $runs, sessions: $sessions, runnerGateway: $runnerGateway, logger: $logger)(new StopPersonalRunJob($run->getId()));
    }

    public function testAllFoundCallsStopSession(): void
    {
        $now = new \DateTimeImmutable();
        $run = Run::create('owner-1', 'Test Run', $now);
        $run->setSessionId('sess-1');

        $session = Session::create('sess-1', 'event-1', $now);

        $runs = $this->createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $sessions = $this->createStub(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn($session);

        $runnerGateway = $this->createMock(RunnerGatewayInterface::class);
        $runnerGateway->expects($this->once())->method('stopSession')->with('sess-1');

        $this->makeHandler(runs: $runs, sessions: $sessions, runnerGateway: $runnerGateway)(new StopPersonalRunJob($run->getId()));
    }

    private function makeHandler(
        ?RunRepositoryInterface $runs = null,
        ?SessionRepositoryInterface $sessions = null,
        ?RunnerGatewayInterface $runnerGateway = null,
        ?LoggerInterface $logger = null,
    ): StopPersonalRunJobHandler {
        return new StopPersonalRunJobHandler(
            $runs ?? $this->createStub(RunRepositoryInterface::class),
            $sessions ?? $this->createStub(SessionRepositoryInterface::class),
            $runnerGateway ?? $this->createStub(RunnerGatewayInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}
