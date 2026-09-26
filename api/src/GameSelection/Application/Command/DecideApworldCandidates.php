<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Exception\ApworldIntrospectionUnavailableException;
use App\GameSelection\Application\Port\ExclusivePassLockInterface;
use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Repository\ApworldCandidateRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use Psr\Clock\ClockInterface;

/**
 * Decides every apworld candidate in test from the orchestrator's verdicts (story 38.6): a passed
 * test promotes, a failed or impossible test rejects - the game keeps its apworld and an "update
 * rejected" incident says so - and a test that never answers expires at its deadline.
 *
 * Same rule as the incident reconciliation: an unreachable runner decides nothing, and does not count
 * against the deadline either - an outage is not a timeout. For the same reason, an expired candidate
 * is not rejected (story 38.6 review): the release stays eligible for the next night, and a promotion
 * whose introspection did not answer waits for the next pass instead of wiping the game's tables.
 *
 * Shares the incident reconciliation's pass lock: two passes never decide the same candidate.
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
        private ExclusivePassLockInterface $lock,
    ) {
    }

    /**
     * @param array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}>|null $verdicts
     *                                                                                                                              the verdicts when the caller already read them for this pass; null reads them here
     */
    public function decide(?array $verdicts = null): DecideApworldCandidatesResult
    {
        if (!$this->lock->tryAcquire(ReconcileApworldIncidents::PASS)) {
            return new DecideApworldCandidatesResult(true);
        }

        try {
            return $this->decideWith($verdicts);
        } finally {
            $this->lock->release(ReconcileApworldIncidents::PASS);
        }
    }

    /**
     * @param array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}>|null $verdicts
     */
    private function decideWith(?array $verdicts): DecideApworldCandidatesResult
    {
        $testing = $this->candidates->findAllTesting();
        if ([] === $testing) {
            return new DecideApworldCandidatesResult(true);
        }

        $verdicts ??= $this->runnerGateway->fetchApworldPreflights();
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
                try {
                    $promotion = $this->promote->promote($candidate, null);
                } catch (ApworldIntrospectionUnavailableException) {
                    continue;
                }
                if (null !== $promotion) {
                    $promotions[] = $promotion;
                    $resolved = [...$resolved, ...$promotion->resolvedIncidentIds];
                }
                continue;
            }

            if ($candidate->hasTestTimedOut($now, new \DateInterval(self::TEST_TIMEOUT)) && !\in_array($status, ['failed', 'skipped'], true)) {
                $reason = 'Le test de génération n\'a pas rendu de verdict dans le délai de 30 minutes : il sera retenté.';
                $candidate->expire($reason, $now);
                $opened = [...$opened, ...$this->recordUpdateIncident($candidate, $reason)];
                continue;
            }

            $reason = match ($status) {
                'failed' => '' !== ($verdict['error'] ?? '') ? $verdict['error'] : 'Le test de génération a échoué, sans détail.',
                'skipped' => 'Le test de génération n\'a pas pu tourner : cet apworld ne produit pas de template.',
                default => null,
            };
            if (null === $reason) {
                continue;
            }

            $candidate->reject($reason, $now);
            $opened = [...$opened, ...$this->recordUpdateIncident($candidate, $reason)];
            $rejected[] = $candidate->getId();
        }

        $this->candidates->flush();

        return new DecideApworldCandidatesResult(true, $promotions, $rejected, $opened, $resolved);
    }

    /**
     * @return list<string> the incident opened, if any
     */
    private function recordUpdateIncident(ApworldCandidate $candidate, string $reason): array
    {
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
