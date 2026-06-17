<?php

declare(strict_types=1);

namespace App\Sessions\Application\ScheduledTask;

use App\Sessions\Application\SessionReconcilerInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Watchdog (toutes les 2 min) : repère les sessions bloquées dans un statut transitoire/actif au-delà
 * de leur seuil (Session::STALE_THRESHOLDS) et délègue la résolution forcée au reconciler, qui décide
 * "force l'arrêt ou le démarrage" selon le statut en attente et l'état réel de l'orchestrateur. La
 * logique de transition (avancement du run lié, publication Mercure) vit dans le reconciler, partagée
 * avec le forçage manuel (story 17.14).
 */
#[AsMessageHandler]
final readonly class CleanupStaleSessionsHandler
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private SessionReconcilerInterface $sessionReconciler,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CleanupStaleSessionsTask $task): void
    {
        $now = new \DateTimeImmutable();

        $candidates = $this->sessions->findByStatuses(Session::STALE_STATUSES);

        $cleaned = 0;

        foreach ($candidates as $session) {
            if (!$session->isStale($now)) {
                continue;
            }

            $result = $this->sessionReconciler->reconcilePending($session->getId());

            $action = $result['action'] ?? null;
            if (null === $action || 'skipped' === $action) {
                continue;
            }

            $this->logger->warning('session.cleanup.reconciled', [
                'sessionId' => $session->getId(),
                'from' => $result['from'] ?? null,
                'to' => $result['to'] ?? null,
                'action' => $action,
            ]);

            ++$cleaned;
        }

        if ($cleaned > 0) {
            $this->logger->info('session.cleanup.done', ['cleaned' => $cleaned]);
        }
    }
}
