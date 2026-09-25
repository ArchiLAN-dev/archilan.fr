<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Repository\ApworldCandidateRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use Psr\Clock\ClockInterface;

/**
 * Decides every apworld candidate in test from the orchestrator's verdicts (story 38.6): a passed
 * test promotes, a failed or impossible test rejects - the game keeps its apworld and an "update
 * rejected" incident says so - and a test that never answers is rejected at its deadline.
 *
 * Same rule as the incident reconciliation: an unreachable runner decides nothing, and does not count
 * against the deadline either - an outage is not a timeout.
 */
final readonly class DecideApworldCandidates
{
    /** A solo test takes one to five minutes; half an hour without a verdict means it will not come. */
    public const string TEST_TIMEOUT = 'PT30M';

    public function __construct(
        private ApworldCandidateRepositoryInterface $candidates,
        private RunnerGatewayInterface $runnerGateway,
        private PromoteApworldCandidate $promote,
        private RecordApworldIncident $recordIncident,
        private ClockInterface $clock,
    ) {
    }

    public function decide(): DecideApworldCandidatesResult
    {
        $testing = $this->candidates->findAllTesting();
        if ([] === $testing) {
            return new DecideApworldCandidatesResult(true);
        }

        $verdicts = $this->runnerGateway->fetchApworldPreflights();
        if ([] === $verdicts) {
            return new DecideApworldCandidatesResult(false);
        }

        $now = $this->clock->now();
        $promotions = [];
        $rejected = [];
        $opened = [];
        $resolved = [];

        foreach ($testing as $candidate) {
            $verdict = $verdicts[$candidate->getApworldHash()] ?? null;
            $status = $verdict['status'] ?? '';

            // An admin already allowed this apworld despite its failed test (story 9.38 AC4).
            if ('passed' === $status || ('failed' === $status && true === ($verdict['overridden'] ?? false))) {
                $promotion = $this->promote->promote($candidate, null);
                if (null !== $promotion) {
                    $promotions[] = $promotion;
                    $resolved = [...$resolved, ...$promotion->resolvedIncidentIds];
                }
                continue;
            }

            $reason = match (true) {
                'failed' === $status => '' !== ($verdict['error'] ?? '') ? $verdict['error'] : 'Le test de génération a échoué, sans détail.',
                'skipped' === $status => 'Le test de génération n\'a pas pu tourner : cet apworld ne produit pas de template.',
                $candidate->hasTestTimedOut($now, new \DateInterval(self::TEST_TIMEOUT)) => 'Le test de génération n\'a pas rendu de verdict dans le délai de 30 minutes.',
                default => null,
            };
            if (null === $reason) {
                continue;
            }

            $opened = [...$opened, ...$this->reject($candidate, $reason, $now)];
            $rejected[] = $candidate->getId();
        }

        $this->candidates->flush();

        return new DecideApworldCandidatesResult(true, $promotions, $rejected, $opened, $resolved);
    }

    /**
     * @return list<string> the incident opened, if any
     */
    private function reject(ApworldCandidate $candidate, string $reason, \DateTimeImmutable $now): array
    {
        $candidate->reject($reason, $now);

        $recording = $this->recordIncident->record(
            $candidate->getGameId(),
            $candidate->getApworldHash(),
            ApworldIncidentType::UpdateRejected,
            $reason,
        );

        return ApworldIncidentRecordOutcome::Opened === $recording->outcome && null !== $recording->incidentId
            ? [$recording->incidentId]
            : [];
    }
}
