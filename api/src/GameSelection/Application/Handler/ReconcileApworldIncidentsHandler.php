<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Handler;

use App\GameSelection\Application\Command\ReconcileApworldIncidents;
use App\GameSelection\Application\Message\ReconcileApworldIncidentsMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReconcileApworldIncidentsHandler
{
    public function __construct(
        private ReconcileApworldIncidents $reconcile,
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

        if ([] !== $result->openedIncidentIds || [] !== $result->resolvedIncidentIds || [] !== $result->ignoredIncidentIds) {
            $this->logger->info('apworld_incidents.reconciled', [
                'opened' => $result->openedIncidentIds,
                'resolved' => $result->resolvedIncidentIds,
                'ignored' => $result->ignoredIncidentIds,
            ]);
        }
    }
}
