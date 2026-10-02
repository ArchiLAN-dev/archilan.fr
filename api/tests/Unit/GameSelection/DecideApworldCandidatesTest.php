<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\DecideApworldCandidates;
use App\GameSelection\Application\Command\DecideApworldCandidatesResult;
use App\GameSelection\Application\Command\PromoteApworldCandidate;
use App\GameSelection\Application\Command\RecordApworldIncident;
use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\ApworldCandidateOrigin;
use App\GameSelection\Domain\Enum\ApworldCandidateStatus;
use App\GameSelection\Domain\Enum\ApworldIncidentStatus;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class DecideApworldCandidatesTest extends TestCase
{
    private Game $game;
    private InMemoryApworldCandidateRepository $candidates;
    private InMemoryApworldIncidentRepository $incidents;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->game = Game::create('Crystal Project', 'crystal-project', 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        $this->game->updateCatalogueMetadata(sourceUrl: 'https://github.com/Emerassi/CrystalProjectAPWorld', deployedVersion: 'CrystalProject-v0.17.0');
        $this->game->configureApworld('hash-old.apworld', 'hash-old', 'Crystal Project', "old: yaml\n", new \DateTimeImmutable('2026-07-16'));
        $this->candidates = new InMemoryApworldCandidateRepository();
        $this->incidents = new InMemoryApworldIncidentRepository();
        $this->clock = new MockClock('2026-09-25 04:15:00+00:00');
    }

    public function testAPassedVerdictPromotesTheCandidate(): void
    {
        $candidate = $this->candidate('hash-new', 'CrystalProject-v0.18.2');

        $result = $this->decide(['hash-new' => $this->verdict('passed')], optionTypes: ['goal' => ['type' => 'choice', 'values' => ['astley']]]);

        self::assertSame(ApworldCandidateStatus::Promoted, $candidate->getStatus());
        self::assertSame('hash-new', $this->game->getApworldHash());
        self::assertSame("new: yaml\n", $this->game->getDefaultYaml());
        self::assertSame('CrystalProject-v0.18.2', $this->game->getApworldDeployedVersion());
        self::assertSame(['goal' => ['type' => 'choice', 'default' => null, 'values' => ['astley']]], $this->game->getOptionTypes(), 'option types are read at promotion');
        self::assertCount(1, $result->promotions);
        $promotion = $result->promotions[0];
        self::assertSame($candidate->getId(), $promotion->candidateId);
        self::assertSame('hash-old', $promotion->previousHash);
        self::assertSame('hash-new', $promotion->newHash);
        self::assertSame('CrystalProject-v0.17.0', $promotion->previousVersion);
        self::assertSame('CrystalProject-v0.18.2', $promotion->newVersion);
        self::assertSame("old: yaml\n", $promotion->previousDefaultYaml);
        self::assertSame(1, $this->candidates->flushes);
    }

    public function testAPassWithAWarningPromotesAsWell(): void
    {
        // Story 38.12: a warning (accessibility not met, as the Launcher allows) is shown to the admin; the
        // test still passed.
        $candidate = $this->candidate('hash-new', 'CrystalProject-v0.18.2');

        $this->decide(['hash-new' => ['warning' => 'Missing: [A]'] + $this->verdict('passed')]);

        self::assertSame(ApworldCandidateStatus::Promoted, $candidate->getStatus());
    }

    public function testAPromotionKeepsTheStory951RuleOnDictVocabularies(): void
    {
        // The rule used to run only when an upload switched the game at once. A sub-setting with fewer
        // than two known values is dropped: half a vocabulary in a dropdown reads as authoritative.
        $this->candidate('hash-new', 'v2');

        $this->decide(['hash-new' => $this->verdict('passed')], optionTypes: [
            'game_options' => ['type' => 'dict', 'values' => ['difficulty', 'seed'], 'keys' => [
                'difficulty' => ['values' => ['easy', 'hard']],
                'seed' => ['values' => ['random']],
            ]],
        ]);

        self::assertSame(
            ['game_options' => ['type' => 'dict', 'default' => null, 'values' => ['difficulty', 'seed'], 'keys' => ['difficulty' => ['values' => ['easy', 'hard']]]]],
            $this->game->getOptionTypes(),
        );
    }

    public function testAPromotionSettlesTheEarlierUpdateIncidentsOfTheGame(): void
    {
        $rejected = ApworldIncident::open('incident-rejected', $this->game->getId(), 'hash-bad', ApworldIncidentType::UpdateRejected, 'boom', new \DateTimeImmutable('2026-09-24'));
        $unrelated = ApworldIncident::open('incident-served', $this->game->getId(), 'hash-old', ApworldIncidentType::PreflightFailed, 'boom', new \DateTimeImmutable('2026-09-24'));
        $this->incidents->save($rejected);
        $this->incidents->save($unrelated);
        $this->candidate('hash-new', 'v2');

        $result = $this->decide(['hash-new' => $this->verdict('passed')]);

        self::assertSame(ApworldIncidentStatus::Resolved, $rejected->getStatus());
        self::assertSame(ApworldIncidentStatus::Open, $unrelated->getStatus(), 'the served-apworld incident is the reconciliation\'s business');
        self::assertSame(['incident-rejected'], $result->resolvedIncidentIds);
    }

    public function testAFailedVerdictRejectsAndOpensAnUpdateRejectedIncident(): void
    {
        $candidate = $this->candidate('hash-new', 'CrystalProject-v0.18.2');

        $result = $this->decide(['hash-new' => $this->verdict('failed', 'Fill.FillError: boom')]);

        self::assertSame(ApworldCandidateStatus::Rejected, $candidate->getStatus());
        self::assertSame('Fill.FillError: boom', $candidate->getRejectionReason());
        self::assertSame('hash-old', $this->game->getApworldHash(), 'the game keeps its apworld');
        self::assertSame([$candidate->getId()], $result->rejectedCandidateIds);
        self::assertCount(1, $result->openedIncidentIds);
        $incident = $this->incidents->findById($result->openedIncidentIds[0]);
        self::assertSame(ApworldIncidentType::UpdateRejected, $incident?->getType());
        self::assertSame('hash-new', $incident->getApworldHash());
    }

    public function testASkippedVerdictRejectsForTheMissingTemplate(): void
    {
        $candidate = $this->candidate('hash-new', 'v2');

        $this->decide(['hash-new' => $this->verdict('skipped')]);

        self::assertSame(ApworldCandidateStatus::Rejected, $candidate->getStatus());
        self::assertStringContainsString('template', (string) $candidate->getRejectionReason());
    }

    public function testAPendingVerdictWaitsUntilTheDeadlineThenExpires(): void
    {
        // Story 38.6 review: no verdict in time is an orchestrator problem (a queue, a restart), not a
        // broken release. The candidate expires; it is not rejected, so the next night tries it again.
        $candidate = $this->candidate('hash-new', 'v2');

        $this->decide(['hash-new' => $this->verdict('pending')]);
        self::assertSame(ApworldCandidateStatus::Testing, $candidate->getStatus());

        $this->clock->sleep(31 * 60);
        $result = $this->decide(['hash-new' => $this->verdict('pending')]);
        self::assertSame(ApworldCandidateStatus::Expired, $candidate->getStatus());
        self::assertStringContainsString('délai', (string) $candidate->getRejectionReason());
        self::assertFalse($this->candidates->hasRejectedVersion($this->game->getId(), 'v2'), 'the release stays eligible');
        self::assertCount(1, $result->openedIncidentIds, 'the admins still hear about it');
    }

    public function testAnIntrospectionThatDidNotAnswerPostponesThePromotion(): void
    {
        // Story 38.6 review: the gateway reads an orchestrator failure as an empty list. Promoting on it
        // would wipe the option types and location names of the game.
        $candidate = $this->candidate('hash-new', 'v2');

        $postponed = $this->decide(['hash-new' => $this->verdict('passed')], optionTypes: [], locations: []);

        self::assertSame(ApworldCandidateStatus::Testing, $candidate->getStatus());
        self::assertSame('hash-old', $this->game->getApworldHash());
        self::assertSame([], $postponed->promotions);

        $this->decide(['hash-new' => $this->verdict('passed')]);
        self::assertSame(ApworldCandidateStatus::Promoted, $candidate->getStatus());
    }

    public function testAPassAlreadyRunningDecidesNothing(): void
    {
        $candidate = $this->candidate('hash-new', 'v2');

        $this->decide(['hash-new' => $this->verdict('passed')], lockHeld: true);

        self::assertSame(ApworldCandidateStatus::Testing, $candidate->getStatus());
    }

    public function testAFailedVerdictAnAdminAlreadyOverrodePromotes(): void
    {
        $candidate = $this->candidate('hash-new', 'v2');

        $this->decide(['hash-new' => $this->verdict('failed', 'boom', overridden: true)]);

        self::assertSame(ApworldCandidateStatus::Promoted, $candidate->getStatus());
    }

    public function testAFirstApworldMakesTheGamePlayable(): void
    {
        $this->game = Game::create('New Game', 'new-game', 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        $this->candidate('hash-first', null);

        $this->decide(['hash-first' => $this->verdict('passed')]);

        self::assertSame('hash-first', $this->game->getApworldHash());
    }

    public function testRunnerUnavailableChangesNothing(): void
    {
        $candidate = $this->candidate('hash-new', 'v2');
        $this->clock->sleep(3600);

        $result = $this->decide([]);

        self::assertFalse($result->runnerAvailable);
        self::assertSame(ApworldCandidateStatus::Testing, $candidate->getStatus(), 'an outage is not a timeout');
        self::assertSame(0, $this->candidates->flushes);
    }

    /** Story 38.14: an admin asked to validate the version themselves - a passed test does not put it online. */
    public function testAHeldCandidateThatPassesWaitsForTheAdmin(): void
    {
        $candidate = $this->candidate('hash-new', 'v2', hold: true);

        $result = $this->decide(['hash-new' => $this->verdict('passed')]);

        self::assertSame(ApworldCandidateStatus::Awaiting, $candidate->getStatus());
        self::assertSame('hash-old', $this->game->getApworldHash(), 'the game keeps serving its version');
        self::assertSame([], $result->promotions);
        self::assertSame(1, $this->candidates->flushes);
    }

    public function testAHeldCandidateThatFailsIsRejectedAsUsual(): void
    {
        $candidate = $this->candidate('hash-new', 'v2', hold: true);

        $this->decide(['hash-new' => $this->verdict('failed', 'Fill.FillError: boom')]);

        self::assertSame(ApworldCandidateStatus::Rejected, $candidate->getStatus());
    }

    private function candidate(string $hash, ?string $tag, bool $hold = false): ApworldCandidate
    {
        $candidate = ApworldCandidate::submit(
            'candidate-'.$hash,
            $this->game->getId(),
            $hash,
            $hash.'.apworld',
            $hash.'.apworld',
            "new: yaml\n",
            'Crystal Project',
            $tag,
            ApworldCandidateOrigin::Auto,
            null,
            $this->clock->now(),
            holdForApproval: $hold,
        );
        $this->candidates->save($candidate);

        return $candidate;
    }

    /**
     * @param array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}> $verdicts
     * @param array<string, array<string, mixed>>|null                                                               $optionTypes null for a plain world
     * @param list<string>|null                                                                                      $locations   null for a plain world
     */
    private function decide(array $verdicts, ?array $optionTypes = null, ?array $locations = null, bool $lockHeld = false): DecideApworldCandidatesResult
    {
        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturnCallback(fn (string $id): ?Game => $id === $this->game->getId() ? $this->game : null);
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchApworldPreflights')->willReturn($verdicts);
        $runner->method('fetchOptionTypes')->willReturn($optionTypes ?? ['accessibility' => ['type' => 'choice', 'values' => ['full', 'minimal']]]);
        $runner->method('fetchLocationNames')->willReturn($locations ?? ['Spawning Meadows Chest']);

        $decide = new DecideApworldCandidates(
            $this->candidates,
            $runner,
            new PromoteApworldCandidate($games, $this->incidents, $runner, $this->clock),
            new RecordApworldIncident($this->incidents, $this->clock),
            $this->clock,
            new InMemoryExclusivePassLock(held: $lockHeld),
        );

        return $decide->decide();
    }

    /**
     * @return array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}
     */
    private function verdict(string $status, string $error = '', bool $overridden = false): array
    {
        return ['status' => $status, 'error' => $error, 'checkedAt' => '2026-09-25T04:14:00Z', 'overridden' => $overridden, 'blocks' => 'failed' === $status && !$overridden];
    }
}
