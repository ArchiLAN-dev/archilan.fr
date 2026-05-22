<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Application\Handler\StopPersonalRunJobHandler;
use App\PersonalRuns\Application\Message\StopPersonalRunJob;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Sessions\Application\Message\StopRunJob;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class StopPersonalRunJobHandlerTest extends TestCase
{
    public function testPersonalRunNotFoundLogsErrorAndReturns(): void
    {
        $runs = $this->createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('personal_run.stop.not_found', $this->arrayHasKey('runId'));

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $this->makeHandler(runs: $runs, messageBus: $messageBus, logger: $logger)(new StopPersonalRunJob('run-missing'));
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

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $this->makeHandler(runs: $runs, messageBus: $messageBus, logger: $logger)(new StopPersonalRunJob($run->getId()));
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

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $this->makeHandler(runs: $runs, sessions: $sessions, messageBus: $messageBus, logger: $logger)(new StopPersonalRunJob($run->getId()));
    }

    public function testAllFoundDispatchesStopRunJob(): void
    {
        $now = new \DateTimeImmutable();
        $run = Run::create('owner-1', 'Test Run', $now);
        $run->setSessionId('sess-1');

        $session = Session::create('sess-1', 'event-1', $now);

        $runs = $this->createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $sessions = $this->createStub(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn($session);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())->method('dispatch')
            ->with($this->callback(static function (StopRunJob $job): bool {
                return 'sess-1' === $job->sessionId
                    && 0 === $job->port
                    && 0 === $job->bridgePort;
            }))
            ->willReturn(new Envelope(new StopRunJob('sess-1', 0, 0)));

        $this->makeHandler(runs: $runs, sessions: $sessions, messageBus: $messageBus)(new StopPersonalRunJob($run->getId()));
    }

    private function makeHandler(
        ?RunRepositoryInterface $runs = null,
        ?SessionRepositoryInterface $sessions = null,
        ?MessageBusInterface $messageBus = null,
        ?LoggerInterface $logger = null,
    ): StopPersonalRunJobHandler {
        return new StopPersonalRunJobHandler(
            $runs ?? $this->createStub(RunRepositoryInterface::class),
            $sessions ?? $this->createStub(SessionRepositoryInterface::class),
            $messageBus ?? $this->createStub(MessageBusInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}
