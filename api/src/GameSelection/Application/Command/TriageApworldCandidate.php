<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Message\ApworldPromoted;
use App\GameSelection\Application\Message\PostApworldIncidentToStaffChannelJob;
use App\GameSelection\Application\Message\PostApworldPromotionToStaffChannelJob;
use App\GameSelection\Application\Message\StaffAlertEvent;
use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\ApworldCandidateStatus;
use App\GameSelection\Domain\Repository\ApworldCandidateRepositoryInterface;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Exception\RequiredGenerationFileRefusedException;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Sessions\Application\Service\RequiredGenerationFiles;
use App\Sessions\Application\Support\ProvidedGenerationFile;
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
        private RequiredGenerationFiles $requiredGenerationFiles,
        private GameRepositoryInterface $games,
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

        // Read before the override (story 38.6 review): an introspection that does not answer must not
        // leave a verdict forced on an apworld that was never switched.
        $introspection = $this->promote->introspect($candidate);
        if (null === $introspection) {
            return ApworldCandidateTriageOutcome::RunnerUnavailable;
        }

        if (null === $this->runnerGateway->overrideApworldPreflight($candidate->getApworldHash(), true)) {
            return ApworldCandidateTriageOutcome::RunnerUnavailable;
        }

        $promotion = $this->promote->promote($candidate, $adminId, $introspection);
        if (null === $promotion) {
            return ApworldCandidateTriageOutcome::NoCandidate;
        }
        $this->candidates->flush();

        $this->messageBus->dispatch(new PostApworldPromotionToStaffChannelJob($candidate->getId(), $promotion->previousVersion));
        // The update incidents the promotion settled, announced like on the automatic path.
        foreach ($promotion->resolvedIncidentIds as $incidentId) {
            $this->messageBus->dispatch(new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Resolved));
        }
        $this->messageBus->dispatch(ApworldPromoted::of($promotion));

        return ApworldCandidateTriageOutcome::Applied;
    }

    /**
     * Story 38.14: the admin puts online a candidate held for their approval, whose test passed. Same effects as
     * an automatic promotion; no override, the verdict passed. The staff announcement names who validated it.
     */
    public function approve(string $gameId, string $adminId): ApworldCandidateTriageOutcome
    {
        $candidate = $this->candidates->findLatestForGame($gameId);
        if (null === $candidate) {
            return ApworldCandidateTriageOutcome::NoCandidate;
        }
        if (ApworldCandidateStatus::Awaiting !== $candidate->getStatus()) {
            return ApworldCandidateTriageOutcome::Forbidden;
        }

        $introspection = $this->promote->introspect($candidate);
        if (null === $introspection) {
            return ApworldCandidateTriageOutcome::RunnerUnavailable;
        }

        $candidate->approve($adminId);
        $promotion = $this->promote->promote($candidate, null, $introspection);
        if (null === $promotion) {
            return ApworldCandidateTriageOutcome::NoCandidate;
        }
        $this->candidates->flush();

        $this->messageBus->dispatch(new PostApworldPromotionToStaffChannelJob($candidate->getId(), $promotion->previousVersion));
        foreach ($promotion->resolvedIncidentIds as $incidentId) {
            $this->messageBus->dispatch(new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Resolved));
        }
        $this->messageBus->dispatch(ApworldPromoted::of($promotion));

        return ApworldCandidateTriageOutcome::Applied;
    }

    /**
     * Typically after a transient failure: the candidate goes back in test with a fresh deadline.
     *
     * Story 38.28: with the files the admin provided (a ROM), handed to the orchestrator for this test only, as the
     * test of a served apworld does (story 44.6). Without them, a version whose world reads a file could never pass.
     *
     * @param list<ProvidedGenerationFile> $files
     */
    public function retry(string $gameId, array $files = []): ApworldCandidateTriageOutcome
    {
        $candidate = $this->undecidedCandidate($gameId);
        if (null === $candidate) {
            return ApworldCandidateTriageOutcome::NoCandidate;
        }

        if (!\in_array($candidate->getStatus(), [ApworldCandidateStatus::Rejected, ApworldCandidateStatus::Expired], true)) {
            return ApworldCandidateTriageOutcome::Forbidden;
        }

        try {
            $fileIds = $this->requiredGenerationFiles->stage($files);
        } catch (RequiredGenerationFileRefusedException) {
            return ApworldCandidateTriageOutcome::FileRefused;
        }

        // The runner first, the candidate after: a candidate changed in memory and left unflushed would
        // still be written by any later flush in the same request.
        // Story 38.30: a meta-game's candidate is tested with a partner player, like its served version.
        $withPartner = $this->games->findById($gameId)?->testsWithPartner() ?? false;
        if (!$this->runnerGateway->runApworldPreflight($candidate->getApworldHash(), $fileIds, $withPartner)) {
            $this->requiredGenerationFiles->release($fileIds);

            return ApworldCandidateTriageOutcome::RunnerUnavailable;
        }
        $candidate->retry($this->clock->now());
        $this->candidates->flush();

        return ApworldCandidateTriageOutcome::Applied;
    }

    private function undecidedCandidate(string $gameId): ?ApworldCandidate
    {
        $candidate = $this->candidates->findLatestForGame($gameId);

        return null !== $candidate && \in_array($candidate->getStatus(), [ApworldCandidateStatus::Testing, ApworldCandidateStatus::Rejected, ApworldCandidateStatus::Expired], true)
            ? $candidate
            : null;
    }
}
