<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\ApworldCandidateTriageOutcome;
use App\GameSelection\Application\Command\PromoteApworldCandidate;
use App\GameSelection\Application\Command\TriageApworldCandidate;
use App\GameSelection\Application\Message\PostApworldIncidentToStaffChannelJob;
use App\GameSelection\Application\Message\PostApworldPromotionToStaffChannelJob;
use App\GameSelection\Application\Message\StaffAlertEvent;
use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\ApworldCandidateOrigin;
use App\GameSelection\Domain\Enum\ApworldCandidateStatus;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TriageApworldCandidateTest extends TestCase
{
    private Game $game;
    private InMemoryApworldCandidateRepository $candidates;
    private InMemoryApworldIncidentRepository $incidents;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->game = Game::create('Crystal Project', 'crystal-project', 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        $this->game->updateCatalogueMetadata(sourceUrl: 'https://github.com/owner/repo', deployedVersion: 'v1');
        $this->game->configureApworld('hash-old.apworld', 'hash-old', 'Crystal Project', "old\n", new \DateTimeImmutable());
        $this->candidates = new InMemoryApworldCandidateRepository();
        $this->incidents = new InMemoryApworldIncidentRepository();
        $this->clock = new MockClock('2026-09-25 09:00:00+00:00');
    }

    public function testForcingPromotesARejectedCandidateOverridesItsVerdictAndAnnouncesIt(): void
    {
        $candidate = $this->candidate(rejected: true);
        $runner = $this->runner(overrideAnswers: true, expectedOverride: 'hash-new');
        $bus = new SpyMessageBus($this->incidents);

        $outcome = $this->triage($runner, $bus)->forcePromote($this->game->getId(), 'admin-1');

        self::assertSame(ApworldCandidateTriageOutcome::Applied, $outcome);
        self::assertSame(ApworldCandidateStatus::Promoted, $candidate->getStatus());
        self::assertSame('admin-1', $candidate->getForcedBy());
        self::assertSame('hash-new', $this->game->getApworldHash());
        self::assertSame(1, $this->candidates->flushes);
        self::assertEquals([new PostApworldPromotionToStaffChannelJob($candidate->getId(), 'v1')], $bus->messages());
    }

    public function testACandidateStillInTestCanBeForcedToo(): void
    {
        $candidate = $this->candidate(rejected: false);

        $outcome = $this->triage($this->runner(overrideAnswers: true), new SpyMessageBus($this->incidents))->forcePromote($this->game->getId(), 'admin-1');

        self::assertSame(ApworldCandidateTriageOutcome::Applied, $outcome);
        self::assertSame(ApworldCandidateStatus::Promoted, $candidate->getStatus());
    }

    public function testForcingWhenTheRunnerCannotRecordTheOverrideChangesNothing(): void
    {
        // Without the override on the verdict, the incident reconciliation would open a "test failed"
        // incident on the apworld the admin just forced.
        $candidate = $this->candidate(rejected: true);
        $bus = new SpyMessageBus($this->incidents);

        $outcome = $this->triage($this->runner(overrideAnswers: false), $bus)->forcePromote($this->game->getId(), 'admin-1');

        self::assertSame(ApworldCandidateTriageOutcome::RunnerUnavailable, $outcome);
        self::assertSame(ApworldCandidateStatus::Rejected, $candidate->getStatus());
        self::assertSame('hash-old', $this->game->getApworldHash());
        self::assertSame([], $bus->dispatched);
    }

    public function testForcingWhenTheIntrospectionDoesNotAnswerChangesNothing(): void
    {
        // Story 38.6 review: checked before the verdict is overridden, so nothing is half done.
        $candidate = $this->candidate(rejected: true);
        $runner = $this->createMock(RunnerGatewayInterface::class);
        $runner->expects(self::never())->method('overrideApworldPreflight');
        $runner->method('fetchOptionTypes')->willReturn([]);
        $runner->method('fetchLocationNames')->willReturn([]);

        $outcome = $this->triage($runner, new SpyMessageBus($this->incidents))->forcePromote($this->game->getId(), 'admin-1');

        self::assertSame(ApworldCandidateTriageOutcome::RunnerUnavailable, $outcome);
        self::assertSame(ApworldCandidateStatus::Rejected, $candidate->getStatus());
        self::assertSame('hash-old', $this->game->getApworldHash());
    }

    public function testForcingAnnouncesTheUpdateIncidentsItSettles(): void
    {
        // Story 38.6 review: the automatic path posted these, the forced one did not.
        $this->candidate(rejected: true);
        $rejected = ApworldIncident::open('incident-1', $this->game->getId(), 'hash-new', ApworldIncidentType::UpdateRejected, 'Fill.FillError: boom', new \DateTimeImmutable('2026-09-25 04:20:00+00:00'));
        $this->incidents->save($rejected);
        $bus = new SpyMessageBus($this->incidents);

        $this->triage($this->runner(overrideAnswers: true), $bus)->forcePromote($this->game->getId(), 'admin-1');

        self::assertContainsEquals(new PostApworldIncidentToStaffChannelJob('incident-1', StaffAlertEvent::Resolved), $bus->messages());
    }

    public function testForcingWithoutACandidateSaysSo(): void
    {
        $outcome = $this->triage($this->runner(overrideAnswers: true), new SpyMessageBus($this->incidents))->forcePromote($this->game->getId(), 'admin-1');

        self::assertSame(ApworldCandidateTriageOutcome::NoCandidate, $outcome);
    }

    public function testRetryPutsARejectedCandidateBackInTestAndRerunsItsTest(): void
    {
        $candidate = $this->candidate(rejected: true);
        $runner = $this->createMock(RunnerGatewayInterface::class);
        $runner->expects(self::once())->method('runApworldPreflight')->with('hash-new')->willReturn(true);

        $outcome = $this->triage($runner, new SpyMessageBus($this->incidents))->retry($this->game->getId());

        self::assertSame(ApworldCandidateTriageOutcome::Applied, $outcome);
        self::assertSame(ApworldCandidateStatus::Testing, $candidate->getStatus());
        self::assertEquals($this->clock->now(), $candidate->getSubmittedAt());
        self::assertSame(1, $this->candidates->flushes);
    }

    public function testRetryWhenTheRunnerRefusesChangesNothing(): void
    {
        $candidate = $this->candidate(rejected: true);
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('runApworldPreflight')->willReturn(false);

        $outcome = $this->triage($runner, new SpyMessageBus($this->incidents))->retry($this->game->getId());

        self::assertSame(ApworldCandidateTriageOutcome::RunnerUnavailable, $outcome);
        self::assertSame(ApworldCandidateStatus::Rejected, $candidate->getStatus());
        self::assertSame(0, $this->candidates->flushes);
    }

    public function testRetryingACandidateStillInTestIsForbidden(): void
    {
        $this->candidate(rejected: false);

        $outcome = $this->triage(self::createStub(RunnerGatewayInterface::class), new SpyMessageBus($this->incidents))->retry($this->game->getId());

        self::assertSame(ApworldCandidateTriageOutcome::Forbidden, $outcome);
    }

    private function candidate(bool $rejected): ApworldCandidate
    {
        $candidate = ApworldCandidate::submit('candidate-1', $this->game->getId(), 'hash-new', 'hash-new.apworld', 'hash-new.apworld', "new\n", 'Crystal Project', 'v2', ApworldCandidateOrigin::Manual, 'admin-1', new \DateTimeImmutable('2026-09-25 04:10:00+00:00'));
        if ($rejected) {
            $candidate->reject('Fill.FillError: boom', new \DateTimeImmutable('2026-09-25 04:20:00+00:00'));
        }
        $this->candidates->save($candidate);

        return $candidate;
    }

    private function runner(bool $overrideAnswers, ?string $expectedOverride = null): RunnerGatewayInterface
    {
        $runner = $this->createMock(RunnerGatewayInterface::class);
        $runner->expects(self::atMost(1))->method('overrideApworldPreflight')
            ->with($expectedOverride ?? self::anything(), true)
            ->willReturn($overrideAnswers ? ['status' => 'failed', 'error' => '', 'checkedAt' => '', 'overridden' => true, 'blocks' => false] : null);
        $runner->method('fetchOptionTypes')->willReturn(['accessibility' => ['type' => 'choice', 'values' => ['full', 'minimal']]]);
        $runner->method('fetchLocationNames')->willReturn(['Spawning Meadows Chest']);

        return $runner;
    }

    private function triage(RunnerGatewayInterface $runner, SpyMessageBus $bus): TriageApworldCandidate
    {
        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturn($this->game);

        return new TriageApworldCandidate(
            $this->candidates,
            $runner,
            new PromoteApworldCandidate($games, $this->incidents, $runner, $this->clock),
            $bus,
            $this->clock,
        );
    }
}
