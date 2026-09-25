<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Message\PostApworldPromotionToStaffChannelJob;
use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Enum\ApworldCandidateStatus;
use App\GameSelection\Domain\Repository\ApworldCandidateRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * An admin overrules the test of a game's apworld candidate (story 38.6 AC 4): force it into service
 * despite a failed or pending test, or rerun the test of a rejected one.
 */
final readonly class TriageApworldCandidate
{
    public function __construct(
        private ApworldCandidateRepositoryInterface $candidates,
        private RunnerGatewayInterface $runnerGateway,
        private PromoteApworldCandidate $promote,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The verdict is marked as overridden on the orchestrator first (story 9.38 AC4): otherwise the
     * incident reconciliation would open a "test failed" incident on the apworld just forced. If the
     * orchestrator cannot record it, nothing is switched.
     */
    public function forcePromote(string $gameId, string $adminId): ApworldCandidateTriageOutcome
    {
        $candidate = $this->undecidedCandidate($gameId);
        if (null === $candidate) {
            return ApworldCandidateTriageOutcome::NoCandidate;
        }

        if (null === $this->runnerGateway->overrideApworldPreflight($candidate->getApworldHash(), true)) {
            return ApworldCandidateTriageOutcome::RunnerUnavailable;
        }

        $promotion = $this->promote->promote($candidate, $adminId);
        if (null === $promotion) {
            return ApworldCandidateTriageOutcome::NoCandidate;
        }
        $this->candidates->flush();

        $this->messageBus->dispatch(new PostApworldPromotionToStaffChannelJob($candidate->getId(), $promotion->previousVersion));

        return ApworldCandidateTriageOutcome::Applied;
    }

    /**
     * Typically after a transient failure: the candidate goes back in test with a fresh deadline.
     */
    public function retry(string $gameId): ApworldCandidateTriageOutcome
    {
        $candidate = $this->undecidedCandidate($gameId);
        if (null === $candidate) {
            return ApworldCandidateTriageOutcome::NoCandidate;
        }

        if (ApworldCandidateStatus::Rejected !== $candidate->getStatus()) {
            return ApworldCandidateTriageOutcome::Forbidden;
        }

        // The runner first, the candidate after: a candidate changed in memory and left unflushed would
        // still be written by any later flush in the same request.
        if (!$this->runnerGateway->runApworldPreflight($candidate->getApworldHash())) {
            return ApworldCandidateTriageOutcome::RunnerUnavailable;
        }
        $candidate->retry($this->clock->now());
        $this->candidates->flush();

        return ApworldCandidateTriageOutcome::Applied;
    }

    private function undecidedCandidate(string $gameId): ?ApworldCandidate
    {
        $candidate = $this->candidates->findLatestForGame($gameId);

        return null !== $candidate && \in_array($candidate->getStatus(), [ApworldCandidateStatus::Testing, ApworldCandidateStatus::Rejected], true)
            ? $candidate
            : null;
    }
}
