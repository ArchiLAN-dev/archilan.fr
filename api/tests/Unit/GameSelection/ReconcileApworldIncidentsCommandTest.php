<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\ReconcileApworldIncidents;
use App\GameSelection\Application\Command\RecordApworldIncident;
use App\GameSelection\Application\Message\NotifyApworldIncidentAdminsJob;
use App\GameSelection\Application\Message\PostApworldIncidentToStaffChannelJob;
use App\GameSelection\Application\Query\ServedApworld;
use App\GameSelection\Application\Query\ServedApworldsQueryInterface;
use App\GameSelection\Application\Support\ApworldIncidentAlertDispatcher;
use App\GameSelection\Presentation\Command\ReconcileApworldIncidentsCommand;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Story 38.2: a manual run must alert exactly like the scheduled one - an incident opened by hand
 * that nobody hears about is the silent failure this epic removes.
 */
final class ReconcileApworldIncidentsCommandTest extends TestCase
{
    public function testAManualRunSendsTheSameAlertsAsTheScheduledOne(): void
    {
        $incidents = new InMemoryApworldIncidentRepository();
        $bus = new SpyMessageBus($incidents);
        $tester = new CommandTester($this->command($incidents, $bus, [
            'hash-1' => ['status' => 'failed', 'error' => 'boom', 'checkedAt' => '2026-09-24T09:55:00Z', 'overridden' => false, 'blocks' => true],
        ]));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('1 opened', $tester->getDisplay());
        self::assertEquals([
            NotifyApworldIncidentAdminsJob::class,
            PostApworldIncidentToStaffChannelJob::class,
        ], array_map(static fn (object $m): string => $m::class, $bus->messages()));
    }

    public function testRunnerUnavailableFailsAndSendsNothing(): void
    {
        $incidents = new InMemoryApworldIncidentRepository();
        $bus = new SpyMessageBus($incidents);
        $tester = new CommandTester($this->command($incidents, $bus, []));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame([], $bus->dispatched);
    }

    /**
     * @param array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}> $verdicts
     */
    private function command(InMemoryApworldIncidentRepository $incidents, SpyMessageBus $bus, array $verdicts): ReconcileApworldIncidentsCommand
    {
        $served = self::createStub(ServedApworldsQueryInterface::class);
        $served->method('servedApworlds')->willReturn([new ServedApworld('game-1', 'hash-1')]);
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchApworldPreflights')->willReturn($verdicts);
        $clock = new MockClock('2026-09-24 10:00:00+00:00');

        return new ReconcileApworldIncidentsCommand(
            new ReconcileApworldIncidents($served, $runner, $incidents, new RecordApworldIncident($incidents, $clock), $clock, new InMemoryExclusivePassLock()),
            new ApworldIncidentAlertDispatcher($bus),
        );
    }
}
