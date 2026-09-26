<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\DecideApworldCandidates;
use App\GameSelection\Application\Command\PromoteApworldCandidate;
use App\GameSelection\Application\Command\ReconcileApworldIncidents;
use App\GameSelection\Application\Command\RecordApworldIncident;
use App\GameSelection\Application\Message\NotifyApworldIncidentAdminsJob;
use App\GameSelection\Application\Message\PostApworldIncidentToStaffChannelJob;
use App\GameSelection\Application\Message\PostApworldPromotionToStaffChannelJob;
use App\GameSelection\Application\Query\ServedApworld;
use App\GameSelection\Application\Query\ServedApworldsQueryInterface;
use App\GameSelection\Application\Support\ApworldIncidentAlertDispatcher;
use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\ApworldCandidateOrigin;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
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

    public function testAManualRunAlsoDecidesTheCandidatesInTest(): void
    {
        // Story 38.6: the command is the whole five-minute pass, not half of it.
        $incidents = new InMemoryApworldIncidentRepository();
        $candidates = new InMemoryApworldCandidateRepository();
        $game = Game::create('Crystal Project', 'crystal-project', 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        $candidates->save(ApworldCandidate::submit('candidate-1', $game->getId(), 'hash-new', 'hash-new.apworld', 'hash-new.apworld', "game: x\n", 'Crystal Project', 'v2', ApworldCandidateOrigin::Manual, 'admin-1', new \DateTimeImmutable('2026-09-24 09:55:00+00:00')));
        $bus = new SpyMessageBus($incidents);
        $tester = new CommandTester($this->command($incidents, $bus, [
            'hash-new' => ['status' => 'passed', 'error' => '', 'checkedAt' => '2026-09-24T09:58:00Z', 'overridden' => false, 'blocks' => false],
        ], $candidates, $game));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Apworld candidates: 1 promoted, 0 rejected.', $tester->getDisplay());
        self::assertSame('hash-new', $game->getApworldHash());
        self::assertContainsOnlyInstancesOf(PostApworldPromotionToStaffChannelJob::class, $bus->messages());
    }

    /**
     * @param array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}> $verdicts
     */
    private function command(InMemoryApworldIncidentRepository $incidents, SpyMessageBus $bus, array $verdicts, ?InMemoryApworldCandidateRepository $candidates = null, ?Game $game = null): ReconcileApworldIncidentsCommand
    {
        $served = self::createStub(ServedApworldsQueryInterface::class);
        $served->method('servedApworlds')->willReturn([new ServedApworld('game-1', 'hash-1')]);
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchApworldPreflights')->willReturn($verdicts);
        $runner->method('fetchOptionTypes')->willReturn(['accessibility' => ['type' => 'choice', 'values' => ['full', 'minimal']]]);
        $runner->method('fetchLocationNames')->willReturn(['Spawning Meadows Chest']);
        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturn($game);
        $clock = new MockClock('2026-09-24 10:00:00+00:00');
        $record = new RecordApworldIncident($incidents, $clock);

        return new ReconcileApworldIncidentsCommand(
            new DecideApworldCandidates($candidates ?? new InMemoryApworldCandidateRepository(), $runner, new PromoteApworldCandidate($games, $incidents, $runner, $clock), $record, $clock, new InMemoryExclusivePassLock()),
            $runner,
            new ReconcileApworldIncidents($served, $runner, $incidents, $record, $clock, new InMemoryExclusivePassLock()),
            new ApworldIncidentAlertDispatcher($bus),
        );
    }
}
