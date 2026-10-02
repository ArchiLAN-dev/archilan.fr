<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Enum\ApworldCandidateOrigin;
use App\GameSelection\Domain\Enum\ApworldCandidateStatus;
use App\GameSelection\Domain\Exception\ApworldCandidateTransitionException;
use PHPUnit\Framework\TestCase;

final class ApworldCandidateTest extends TestCase
{
    private const string SUBMITTED_AT = '2026-09-25 04:10:00+00:00';

    public function testASubmittedCandidateIsInTestAndCarriesWhatPromotionNeeds(): void
    {
        $candidate = $this->candidate();

        self::assertSame(ApworldCandidateStatus::Testing, $candidate->getStatus());
        self::assertSame('game-1', $candidate->getGameId());
        self::assertSame('hash-new', $candidate->getApworldHash());
        self::assertSame('hash-new.apworld', $candidate->getStorageKey());
        self::assertSame('hash-new.apworld', $candidate->getMinioKey());
        self::assertSame("game: Crystal Project\n", $candidate->getDefaultYaml());
        self::assertSame('Crystal Project', $candidate->getArchipelagoGameName());
        self::assertSame('CrystalProject-v0.18.2', $candidate->getVersionTag());
        self::assertSame(ApworldCandidateOrigin::Auto, $candidate->getOrigin());
        self::assertNull($candidate->getSubmittedBy());
        self::assertNull($candidate->getDecidedAt());
    }

    public function testPromotionIsFinalAndRecordsWhoForcedIt(): void
    {
        $candidate = $this->candidate();
        $at = new \DateTimeImmutable('2026-09-25 04:20:00+00:00');

        $candidate->promote($at, 'admin-1');

        self::assertSame(ApworldCandidateStatus::Promoted, $candidate->getStatus());
        self::assertEquals($at, $candidate->getDecidedAt());
        self::assertSame('admin-1', $candidate->getForcedBy());
    }

    public function testAnAutomaticPromotionHasNoForcingAdmin(): void
    {
        $candidate = $this->candidate();

        $candidate->promote(new \DateTimeImmutable('2026-09-25 04:20:00+00:00'), null);

        self::assertNull($candidate->getForcedBy());
    }

    public function testRejectionKeepsTheReason(): void
    {
        $candidate = $this->candidate();

        $candidate->reject('Fill.FillError: boom', new \DateTimeImmutable('2026-09-25 04:20:00+00:00'));

        self::assertSame(ApworldCandidateStatus::Rejected, $candidate->getStatus());
        self::assertSame('Fill.FillError: boom', $candidate->getRejectionReason());
    }

    public function testARejectedCandidateCanBeForcedByAnAdmin(): void
    {
        $candidate = $this->candidate();
        $candidate->reject('boom', new \DateTimeImmutable('2026-09-25 04:20:00+00:00'));

        $candidate->promote(new \DateTimeImmutable('2026-09-25 09:00:00+00:00'), 'admin-1');

        self::assertSame(ApworldCandidateStatus::Promoted, $candidate->getStatus());
    }

    public function testRetryPutsARejectedCandidateBackInTest(): void
    {
        $candidate = $this->candidate();
        $candidate->reject('boom', new \DateTimeImmutable('2026-09-25 04:20:00+00:00'));
        $retryAt = new \DateTimeImmutable('2026-09-25 09:00:00+00:00');

        $candidate->retry($retryAt);

        self::assertSame(ApworldCandidateStatus::Testing, $candidate->getStatus());
        self::assertNull($candidate->getRejectionReason());
        self::assertNull($candidate->getDecidedAt());
        self::assertEquals($retryAt, $candidate->getSubmittedAt(), 'the test deadline starts again');
    }

    public function testANewerSubmissionSupersedesTheCandidateInTest(): void
    {
        $candidate = $this->candidate();

        $candidate->supersede(new \DateTimeImmutable('2026-09-25 04:15:00+00:00'));

        self::assertSame(ApworldCandidateStatus::Superseded, $candidate->getStatus());
    }

    public function testTheTestHasADeadline(): void
    {
        $candidate = $this->candidate();
        $timeout = new \DateInterval('PT30M');

        self::assertFalse($candidate->hasTestTimedOut(new \DateTimeImmutable('2026-09-25 04:39:59+00:00'), $timeout));
        self::assertTrue($candidate->hasTestTimedOut(new \DateTimeImmutable('2026-09-25 04:40:00+00:00'), $timeout));
    }

    public function testAPromotedCandidateCannotBeRejected(): void
    {
        $candidate = $this->candidate();
        $candidate->promote(new \DateTimeImmutable('2026-09-25 04:20:00+00:00'), null);

        $this->expectException(ApworldCandidateTransitionException::class);

        $candidate->reject('late verdict', new \DateTimeImmutable('2026-09-25 04:30:00+00:00'));
    }

    public function testASupersededCandidateCannotBePromoted(): void
    {
        $candidate = $this->candidate();
        $candidate->supersede(new \DateTimeImmutable('2026-09-25 04:15:00+00:00'));

        $this->expectException(ApworldCandidateTransitionException::class);

        $candidate->promote(new \DateTimeImmutable('2026-09-25 04:20:00+00:00'), 'admin-1');
    }

    public function testOnlyARejectedCandidateCanBeRetried(): void
    {
        $candidate = $this->candidate();

        $this->expectException(ApworldCandidateTransitionException::class);

        $candidate->retry(new \DateTimeImmutable('2026-09-25 04:20:00+00:00'));
    }

    /** Story 38.14: a candidate held for approval waits after a passed test, then an admin puts it online. */
    public function testAHeldCandidateWaitsForApprovalThenIsPutOnlineByAnAdmin(): void
    {
        $candidate = $this->candidate(hold: true);
        self::assertTrue($candidate->isHeldForApproval());

        $candidate->awaitApproval(new \DateTimeImmutable('2026-09-25 04:15:00+00:00'));
        self::assertSame(ApworldCandidateStatus::Awaiting, $candidate->getStatus());
        self::assertFalse($candidate->hasTestTimedOut(new \DateTimeImmutable('2026-09-26'), new \DateInterval('PT30M')), 'a tested candidate no longer expires');

        $candidate->approve('admin-1');
        $candidate->promote(new \DateTimeImmutable('2026-09-25 04:20:00+00:00'), null);
        self::assertSame(ApworldCandidateStatus::Promoted, $candidate->getStatus());
        self::assertSame('admin-1', $candidate->getApprovedBy());
        self::assertNull($candidate->getForcedBy(), 'approving after a passed test is not forcing');
    }

    public function testACandidateIsNotHeldByDefault(): void
    {
        self::assertFalse($this->candidate()->isHeldForApproval());
    }

    public function testOnlyACandidateInTestCanAwaitApproval(): void
    {
        $candidate = $this->candidate(hold: true);
        $candidate->reject('boom', new \DateTimeImmutable('2026-09-25 04:15:00+00:00'));

        $this->expectException(ApworldCandidateTransitionException::class);

        $candidate->awaitApproval(new \DateTimeImmutable('2026-09-25 04:16:00+00:00'));
    }

    public function testOnlyAnAwaitingCandidateCanBeApproved(): void
    {
        $candidate = $this->candidate(hold: true);

        $this->expectException(ApworldCandidateTransitionException::class);

        $candidate->approve('admin-1');
    }

    public function testANewerSubmissionSupersedesAnAwaitingCandidate(): void
    {
        $candidate = $this->candidate(hold: true);
        $candidate->awaitApproval(new \DateTimeImmutable('2026-09-25 04:15:00+00:00'));

        $candidate->supersede(new \DateTimeImmutable('2026-09-25 04:30:00+00:00'));

        self::assertSame(ApworldCandidateStatus::Superseded, $candidate->getStatus());
    }

    private function candidate(bool $hold = false): ApworldCandidate
    {
        return ApworldCandidate::submit(
            'candidate-1',
            'game-1',
            'hash-new',
            'hash-new.apworld',
            'hash-new.apworld',
            "game: Crystal Project\n",
            'Crystal Project',
            'CrystalProject-v0.18.2',
            ApworldCandidateOrigin::Auto,
            null,
            new \DateTimeImmutable(self::SUBMITTED_AT),
            holdForApproval: $hold,
        );
    }
}
