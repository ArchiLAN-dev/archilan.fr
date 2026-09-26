<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\DecideApworldCandidates;
use App\GameSelection\Application\Command\PromoteApworldCandidate;
use App\GameSelection\Application\Command\ReconcileApworldIncidents;
use App\GameSelection\Application\Command\RecordApworldIncident;
use App\GameSelection\Application\Query\ServedApworldsQueryInterface;
use App\GameSelection\Application\Support\ApworldIncidentAlertDispatcher;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\GameSelection\Presentation\Command\ReconcileApworldIncidentsCommand;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Story 38.1 review: a pass started while another runs says so and changes nothing.
 */
final class ReconcileApworldIncidentsAlreadyRunningTest extends TestCase
{
    public function testTheConsoleSaysAPassIsAlreadyRunning(): void
    {
        $incidents = new InMemoryApworldIncidentRepository();
        $clock = new MockClock();
        $reconcile = new ReconcileApworldIncidents(
            self::createStub(ServedApworldsQueryInterface::class),
            self::createStub(RunnerGatewayInterface::class),
            $incidents,
            new RecordApworldIncident($incidents, $clock),
            $clock,
            new InMemoryExclusivePassLock(held: true),
        );
        $runner = self::createStub(RunnerGatewayInterface::class);
        $decide = new DecideApworldCandidates(
            new InMemoryApworldCandidateRepository(),
            $runner,
            new PromoteApworldCandidate(self::createStub(GameRepositoryInterface::class), $incidents, $runner, $clock),
            new RecordApworldIncident($incidents, $clock),
            $clock,
            new InMemoryExclusivePassLock(held: true),
        );
        $tester = new CommandTester(new ReconcileApworldIncidentsCommand($decide, $runner, $reconcile, new ApworldIncidentAlertDispatcher(new SpyMessageBus($incidents))));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('A reconciliation is already running: skipped.', $tester->getDisplay());
        self::assertSame(0, $incidents->flushes);
    }
}
