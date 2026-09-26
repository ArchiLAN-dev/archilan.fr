<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\DecideApworldCandidates;
use App\GameSelection\Application\Command\PromoteApworldCandidate;
use App\GameSelection\Application\Command\ReconcileApworldIncidents;
use App\GameSelection\Application\Command\RecordApworldIncident;
use App\GameSelection\Application\Handler\ReconcileApworldIncidentsHandler;
use App\GameSelection\Application\Message\ApworldPromoted;
use App\GameSelection\Application\Message\NotifyApworldIncidentAdminsJob;
use App\GameSelection\Application\Message\PostApworldIncidentToStaffChannelJob;
use App\GameSelection\Application\Message\PostApworldPromotionToStaffChannelJob;
use App\GameSelection\Application\Message\ReconcileApworldIncidentsMessage;
use App\GameSelection\Application\Message\StaffAlertEvent;
use App\GameSelection\Application\Query\ServedApworld;
use App\GameSelection\Application\Query\ServedApworldsQueryInterface;
use App\GameSelection\Application\Support\ApworldIncidentAlertDispatcher;
use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\ApworldCandidateOrigin;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class ReconcileApworldIncidentsHandlerTest extends TestCase
{
    private int $verdictReads = 0;
    private InMemoryApworldIncidentRepository $incidents;
    private InMemoryApworldCandidateRepository $candidates;

    protected function setUp(): void
    {
        $this->incidents = new InMemoryApworldIncidentRepository();
        $this->candidates = new InMemoryApworldCandidateRepository();
    }

    public function testDispatchesOneAdminAndOneStaffJobPerOpenedIncidentAfterFlush(): void
    {
        $bus = $this->reconcileOnce([new ServedApworld('game-1', 'hash-1')], ['hash-1' => $this->verdict('failed')]);

        $incidentId = $this->incidents->all()[0]->getId();
        self::assertEquals([
            new NotifyApworldIncidentAdminsJob($incidentId),
            new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Opened),
        ], $bus->messages());
        foreach ($bus->dispatched as $dispatch) {
            self::assertSame(1, $dispatch['flushesBefore'], 'alerts leave only once the transition is committed');
        }
    }

    public function testTheStaffPostsOfOnePassAreSpacedOut(): void
    {
        // Story 38.2 review: the first pass after a deploy may open dozens of incidents at once, and a
        // Discord webhook takes about five posts per two seconds.
        $bus = $this->reconcileOnce(
            [new ServedApworld('game-1', 'hash-1'), new ServedApworld('game-2', 'hash-2'), new ServedApworld('game-3', 'hash-3')],
            ['hash-1' => $this->verdict('failed'), 'hash-2' => $this->verdict('failed'), 'hash-3' => $this->verdict('failed')],
        );

        $delays = [];
        foreach ($bus->dispatched as $dispatch) {
            if ($dispatch['message'] instanceof PostApworldIncidentToStaffChannelJob) {
                $stamp = array_values(array_filter($dispatch['stamps'], static fn (object $s): bool => $s instanceof DelayStamp))[0] ?? null;
                $delays[] = $stamp instanceof DelayStamp ? $stamp->getDelay() : 0;
            }
        }
        self::assertSame([0, 500, 1000], $delays);
    }

    public function testOnePassReadsTheVerdictsOnce(): void
    {
        // Story 38.6 review: deciding the candidates and reconciling the incidents read the same list, and
        // must see the same snapshot of it.
        $game = $this->gameServing('hash-old', 'v1');
        $this->candidateFor($game, 'hash-new');

        $this->reconcileOnce([new ServedApworld($game->getId(), 'hash-old')], ['hash-new' => $this->verdict('pending')], $game);

        self::assertSame(1, $this->verdictReads);
    }

    public function testRecurrenceDispatchesNothing(): void
    {
        $served = [new ServedApworld('game-1', 'hash-1')];
        $this->reconcileOnce($served, ['hash-1' => $this->verdict('failed')]);

        $bus = $this->reconcileOnce($served, ['hash-1' => $this->verdict('failed')]);

        self::assertSame([], $bus->dispatched);
    }

    public function testAResolutionPostsOnlyToTheStaffChannel(): void
    {
        $served = [new ServedApworld('game-1', 'hash-1')];
        $this->reconcileOnce($served, ['hash-1' => $this->verdict('failed')]);

        $bus = $this->reconcileOnce($served, ['hash-1' => $this->verdict('passed')]);

        $incidentId = $this->incidents->all()[0]->getId();
        self::assertEquals(
            [new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Resolved)],
            $bus->messages(),
        );
    }

    public function testAnOverrideIgnorePostsOnlyToTheStaffChannel(): void
    {
        $served = [new ServedApworld('game-1', 'hash-1')];
        $this->reconcileOnce($served, ['hash-1' => $this->verdict('failed')]);

        $bus = $this->reconcileOnce($served, ['hash-1' => $this->verdict('failed', overridden: true)]);

        $incidentId = $this->incidents->all()[0]->getId();
        self::assertEquals(
            [new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Ignored)],
            $bus->messages(),
        );
    }

    public function testRunnerUnavailableDispatchesNothing(): void
    {
        $bus = $this->reconcileOnce([new ServedApworld('game-1', 'hash-1')], []);

        self::assertSame([], $bus->dispatched);
    }

    public function testAPromotedCandidateIsAnnouncedOnTheStaffChannel(): void
    {
        // Story 38.6 AC 11: no freeze window, so every switch is announced to the staff.
        $game = $this->gameServing('hash-old', 'CrystalProject-v0.17.0');
        $candidate = $this->candidateFor($game, 'hash-new');

        $bus = $this->reconcileOnce([new ServedApworld($game->getId(), 'hash-old')], ['hash-new' => $this->verdict('passed')], $game);

        self::assertEquals(
            [
                new PostApworldPromotionToStaffChannelJob($candidate->getId(), 'CrystalProject-v0.17.0'),
                // Story 38.7: the slots of runs not yet launched follow the game.
                new ApworldPromoted($game->getId(), 'hash-old', 'hash-new', 'game: Crystal Project
'),
            ],
            $bus->messages(),
        );
    }

    public function testARejectedCandidateAlertsLikeAnyOpenedIncident(): void
    {
        $game = $this->gameServing('hash-old', 'v1');
        $this->candidateFor($game, 'hash-new');

        $bus = $this->reconcileOnce([new ServedApworld($game->getId(), 'hash-old')], ['hash-new' => $this->verdict('failed')], $game);

        $incidentId = $this->incidents->all()[0]->getId();
        self::assertEquals([
            new NotifyApworldIncidentAdminsJob($incidentId),
            new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Opened),
        ], $bus->messages());
    }

    private function gameServing(string $hash, string $version): Game
    {
        $game = Game::create('Crystal Project', 'crystal-project', 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        $game->updateCatalogueMetadata(sourceUrl: 'https://github.com/owner/repo', deployedVersion: $version);
        $game->configureApworld($hash.'.apworld', $hash, 'Crystal Project', "game: Crystal Project\n", new \DateTimeImmutable());

        return $game;
    }

    private function candidateFor(Game $game, string $hash): ApworldCandidate
    {
        $candidate = ApworldCandidate::submit('candidate-'.$hash, $game->getId(), $hash, $hash.'.apworld', $hash.'.apworld', "game: Crystal Project\n", 'Crystal Project', 'v2', ApworldCandidateOrigin::Auto, null, new \DateTimeImmutable('2026-09-24 09:55:00+00:00'));
        $this->candidates->save($candidate);

        return $candidate;
    }

    /**
     * @param list<ServedApworld>                                                                                    $served
     * @param array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}> $verdicts
     */
    private function reconcileOnce(array $served, array $verdicts, ?Game $game = null): SpyMessageBus
    {
        $servedQuery = self::createStub(ServedApworldsQueryInterface::class);
        $servedQuery->method('servedApworlds')->willReturn($served);
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchApworldPreflights')->willReturnCallback(function () use ($verdicts): array {
            ++$this->verdictReads;

            return $verdicts;
        });
        $runner->method('fetchOptionTypes')->willReturn(['accessibility' => ['type' => 'choice', 'values' => ['full', 'minimal']]]);
        $runner->method('fetchLocationNames')->willReturn(['Spawning Meadows Chest']);
        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturn($game);
        $clock = new MockClock('2026-09-24 10:00:00+00:00');
        $bus = new SpyMessageBus($this->incidents);
        $record = new RecordApworldIncident($this->incidents, $clock);

        $handler = new ReconcileApworldIncidentsHandler(
            new DecideApworldCandidates($this->candidates, $runner, new PromoteApworldCandidate($games, $this->incidents, $runner, $clock), $record, $clock, new InMemoryExclusivePassLock()),
            $runner,
            new ReconcileApworldIncidents($servedQuery, $runner, $this->incidents, $record, $clock, new InMemoryExclusivePassLock(), new InMemoryApworldHealthRepository()),
            new ApworldIncidentAlertDispatcher($bus),
            new NullLogger(),
        );
        $this->incidents->flushes = 0;

        $handler(new ReconcileApworldIncidentsMessage());

        return $bus;
    }

    /**
     * @return array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}
     */
    private function verdict(string $status, bool $overridden = false): array
    {
        return [
            'status' => $status,
            'error' => 'failed' === $status ? 'boom' : '',
            'checkedAt' => '2026-09-24T09:55:00Z',
            'overridden' => $overridden,
            'blocks' => 'failed' === $status && !$overridden,
        ];
    }
}
