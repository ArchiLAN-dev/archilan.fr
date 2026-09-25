<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Handler;

use App\GameSelection\Application\Command\DecideApworldCandidates;
use App\GameSelection\Application\Command\ReconcileApworldIncidents;
use App\GameSelection\Application\Message\ReconcileApworldIncidentsMessage;
use App\GameSelection\Application\Support\ApworldIncidentAlertDispatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The five-minute apworld pass: decide the candidates in test (story 38.6), then derive the incidents
 * from the verdicts (story 38.1), then send the alerts of story 38.2 for what changed.
 *
 * Candidates first: a game promoted in this pass is then seen serving its new apworld, so an incident
 * on the apworld it just left closes in the same pass. Each step has flushed when it returns, so every
 * alert leaves after the commit.
 */
#[AsMessageHandler]
final readonly class ReconcileApworldIncidentsHandler
{
    public function __construct(
        private DecideApworldCandidates $decideCandidates,
        private ReconcileApworldIncidents $reconcile,
        private ApworldIncidentAlertDispatcher $alerts,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReconcileApworldIncidentsMessage $message): void
    {
        $decisions = $this->decideCandidates->decide();
        $this->alerts->dispatchForDecisions($decisions);
        if ([] !== $decisions->promotions || [] !== $decisions->rejectedCandidateIds) {
            $this->logger->info('apworld_candidates.decided', [
                'promoted' => array_map(static fn ($p): string => $p->candidateId, $decisions->promotions),
                'rejected' => $decisions->rejectedCandidateIds,
            ]);
        }

        $result = $this->reconcile->reconcile();

        // Another pass holds the lock (a console run, or a slow pass): it does the work.
        if ($result->alreadyRunning) {
            return;
        }

        if (!$result->runnerAvailable) {
            $this->logger->warning('apworld_incidents.reconcile_skipped_runner_unavailable');

            return;
        }

        $this->alerts->dispatchFor($result);

        if ([] !== $result->openedIncidentIds || [] !== $result->resolvedIncidentIds || [] !== $result->ignoredIncidentIds) {
            $this->logger->info('apworld_incidents.reconciled', [
                'opened' => $result->openedIncidentIds,
                'resolved' => $result->resolvedIncidentIds,
                'ignored' => $result->ignoredIncidentIds,
            ]);
        }
    }
}
