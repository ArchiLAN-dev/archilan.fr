<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Handler;

use App\GameSelection\Application\Command\ReconcileApworldIncidents;
use App\GameSelection\Application\Message\ReconcileApworldIncidentsMessage;
use App\GameSelection\Application\Support\ApworldIncidentAlertDispatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the incident reconciliation (story 38.1), then sends the alerts of story 38.2 for what it
 * changed. The reconciliation has flushed when it returns, so every alert leaves after the commit.
 */
#[AsMessageHandler]
final readonly class ReconcileApworldIncidentsHandler
{
    public function __construct(
        private ReconcileApworldIncidents $reconcile,
        private ApworldIncidentAlertDispatcher $alerts,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReconcileApworldIncidentsMessage $message): void
    {
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
