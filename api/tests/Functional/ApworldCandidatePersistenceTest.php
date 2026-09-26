<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Enum\ApworldCandidateOrigin;
use App\GameSelection\Domain\Enum\ApworldCandidateStatus;
use App\GameSelection\Domain\Repository\ApworldCandidateRepositoryInterface;

/**
 * Story 38.6: the candidate store against the real schema.
 */
final class ApworldCandidatePersistenceTest extends FunctionalTestCase
{
    private ApworldCandidateRepositoryInterface $candidates;

    protected function setUp(): void
    {
        parent::setUp();

        $candidates = self::getContainer()->get(ApworldCandidateRepositoryInterface::class);
        self::assertInstanceOf(ApworldCandidateRepositoryInterface::class, $candidates);
        $this->candidates = $candidates;
    }

    public function testACandidateRoundTripsWithItsDecision(): void
    {
        $candidate = $this->candidate('candidate-1', 'game-1', 'v2', '2026-09-25 04:10:00+00:00');
        $candidate->reject('Fill.FillError: boom', new \DateTimeImmutable('2026-09-25 04:20:00+00:00'));
        $this->candidates->save($candidate);
        $this->candidates->flush();
        $this->entityManager->clear();

        $reloaded = $this->candidates->findById('candidate-1');

        self::assertInstanceOf(ApworldCandidate::class, $reloaded);
        self::assertSame(ApworldCandidateStatus::Rejected, $reloaded->getStatus());
        self::assertSame(ApworldCandidateOrigin::Auto, $reloaded->getOrigin());
        self::assertSame('Fill.FillError: boom', $reloaded->getRejectionReason());
        self::assertSame("game: Crystal Project\n", $reloaded->getDefaultYaml());
    }

    public function testFindsTheCandidateInTestOfAGame(): void
    {
        $old = $this->candidate('candidate-old', 'game-1', 'v1', '2026-09-24 04:10:00+00:00');
        $old->supersede(new \DateTimeImmutable('2026-09-25 04:00:00+00:00'));
        $this->candidates->save($old);
        $this->candidates->save($this->candidate('candidate-new', 'game-1', 'v2', '2026-09-25 04:10:00+00:00'));
        $this->candidates->save($this->candidate('candidate-other', 'game-2', 'v1', '2026-09-25 04:10:00+00:00'));
        $this->candidates->flush();

        self::assertSame('candidate-new', $this->candidates->findTestingForGame('game-1')?->getId());
        self::assertNull($this->candidates->findTestingForGame('game-3'));
        self::assertEqualsCanonicalizing(
            ['candidate-new', 'candidate-other'],
            array_map(static fn (ApworldCandidate $c): string => $c->getId(), $this->candidates->findAllTesting()),
        );
    }

    public function testFindsTheLatestCandidateOfAGameWhateverItsStatus(): void
    {
        $this->candidates->save($this->candidate('candidate-a', 'game-1', 'v1', '2026-09-20 04:10:00+00:00'));
        $latest = $this->candidate('candidate-b', 'game-1', 'v2', '2026-09-25 04:10:00+00:00');
        $latest->reject('boom', new \DateTimeImmutable('2026-09-25 04:20:00+00:00'));
        $this->candidates->save($latest);
        $this->candidates->flush();

        self::assertSame('candidate-b', $this->candidates->findLatestForGame('game-1')?->getId());
    }

    public function testRemembersWhichVersionsOfAGameWereRejected(): void
    {
        $rejected = $this->candidate('candidate-1', 'game-1', 'CrystalProject-v0.17.0', '2026-09-25 04:10:00+00:00');
        $rejected->reject('boom', new \DateTimeImmutable('2026-09-25 04:20:00+00:00'));
        $this->candidates->save($rejected);
        $this->candidates->save($this->candidate('candidate-2', 'game-1', 'CrystalProject-v0.18.2', '2026-09-25 04:30:00+00:00'));
        $this->candidates->flush();

        self::assertTrue($this->candidates->hasRejectedVersion('game-1', 'CrystalProject-v0.17.0'));
        self::assertFalse($this->candidates->hasRejectedVersion('game-1', 'CrystalProject-v0.18.2'), 'in test, not rejected');
        self::assertFalse($this->candidates->hasRejectedVersion('game-2', 'CrystalProject-v0.17.0'));
    }

    private function candidate(string $id, string $gameId, string $tag, string $submittedAt): ApworldCandidate
    {
        return ApworldCandidate::submit(
            $id,
            $gameId,
            'hash-'.$id,
            'hash-'.$id.'.apworld',
            'hash-'.$id.'.apworld',
            "game: Crystal Project\n",
            'Crystal Project',
            $tag,
            ApworldCandidateOrigin::Auto,
            null,
            new \DateTimeImmutable($submittedAt),
        );
    }
}
