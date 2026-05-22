<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\GameSelection\Domain\GameRepositoryInterface;
use App\Identity\Domain\UserRepositoryInterface;
use App\PersonalRuns\Application\Handler\LaunchPersonalRunJobHandler;
use App\PersonalRuns\Application\Message\LaunchPersonalRunJob;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Sessions\Application\SlotNameGenerator;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\Sessions\Domain\SessionSlotRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class LaunchPersonalRunJobHandlerTest extends TestCase
{
    public function testPersonalRunNotFoundLogsErrorAndReturns(): void
    {
        $runs = $this->createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('personal_run.launch.not_found', $this->arrayHasKey('runId'));

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $this->makeHandler(runs: $runs, messageBus: $messageBus, logger: $logger)(new LaunchPersonalRunJob('run-missing'));
    }

    public function testNoParticipantsLogsErrorAndReturns(): void
    {
        $now = new \DateTimeImmutable();
        $run = Run::create('owner-1', 'Test Run', $now);

        $runs = $this->createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $participants = $this->createStub(RunParticipantRepositoryInterface::class);
        $participants->method('findByRunId')->willReturn([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('personal_run.launch.no_slots', $this->arrayHasKey('runId'));

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $this->makeHandler(runs: $runs, participants: $participants, messageBus: $messageBus, logger: $logger)(new LaunchPersonalRunJob($run->getId()));
    }

    private function makeHandler(
        ?RunRepositoryInterface $runs = null,
        ?RunParticipantRepositoryInterface $participants = null,
        ?MessageBusInterface $messageBus = null,
        ?LoggerInterface $logger = null,
    ): LaunchPersonalRunJobHandler {
        return new LaunchPersonalRunJobHandler(
            $runs ?? $this->createStub(RunRepositoryInterface::class),
            $participants ?? $this->createStub(RunParticipantRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(GameRepositoryInterface::class),
            $this->createStub(SessionRepositoryInterface::class),
            $this->createStub(SessionSlotRepositoryInterface::class),
            new SlotNameGenerator(),
            $messageBus ?? $this->createStub(MessageBusInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}
