<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Application\Handler\StopPersonalRunJobHandler;
use App\PersonalRuns\Application\Message\StopPersonalRunJob;
use App\PersonalRuns\Domain\Run;
use App\Sessions\Application\Message\StopRunJob;
use App\Sessions\Domain\Session;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class StopPersonalRunJobHandlerTest extends TestCase
{
    private EntityManagerInterface&Stub $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
    }

    public function testPersonalRunNotFoundLogsErrorAndReturns(): void
    {
        $this->entityManager->method('find')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('personal_run.stop.not_found', $this->arrayHasKey('runId'));

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $this->makeHandler(messageBus: $messageBus, logger: $logger)(new StopPersonalRunJob('run-missing'));
    }

    public function testPersonalRunHasNoSessionLogsWarningAndReturns(): void
    {
        $now = new \DateTimeImmutable();
        $run = Run::create('owner-1', 'Test Run', $now);

        $this->entityManager->method('find')->willReturn($run);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('personal_run.stop.no_session', $this->arrayHasKey('runId'));

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $this->makeHandler(messageBus: $messageBus, logger: $logger)(new StopPersonalRunJob($run->getId()));
    }

    public function testSessionNotFoundLogsWarningAndReturns(): void
    {
        $now = new \DateTimeImmutable();
        $run = Run::create('owner-1', 'Test Run', $now);
        $run->setSessionId('sess-missing');

        $this->entityManager->method('find')->willReturnCallback(
            static function (string $class) use ($run): ?object {
                return Run::class === $class ? $run : null;
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('personal_run.stop.session_not_found', $this->logicalAnd(
                $this->arrayHasKey('runId'),
                $this->arrayHasKey('sessionId'),
            ));

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $this->makeHandler(messageBus: $messageBus, logger: $logger)(new StopPersonalRunJob($run->getId()));
    }

    public function testAllFoundDispatchesStopRunJob(): void
    {
        $now = new \DateTimeImmutable();
        $run = Run::create('owner-1', 'Test Run', $now);
        $run->setSessionId('sess-1');

        $session = Session::create('sess-1', 'event-1', $now);

        $this->entityManager->method('find')->willReturnCallback(
            static function (string $class) use ($run, $session): ?object {
                if (Run::class === $class) {
                    return $run;
                }
                if (Session::class === $class) {
                    return $session;
                }

                return null;
            }
        );

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())->method('dispatch')
            ->with($this->callback(static function (StopRunJob $job): bool {
                return 'sess-1' === $job->sessionId
                    && 0 === $job->port
                    && 0 === $job->bridgePort;
            }))
            ->willReturn(new Envelope(new StopRunJob('sess-1', 0, 0)));

        $this->makeHandler(messageBus: $messageBus)(new StopPersonalRunJob($run->getId()));
    }

    private function makeHandler(
        ?MessageBusInterface $messageBus = null,
        ?LoggerInterface $logger = null,
    ): StopPersonalRunJobHandler {
        return new StopPersonalRunJobHandler(
            $this->entityManager,
            $messageBus ?? $this->createStub(MessageBusInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}
