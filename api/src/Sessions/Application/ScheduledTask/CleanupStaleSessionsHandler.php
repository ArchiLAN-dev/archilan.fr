<?php

declare(strict_types=1);

namespace App\Sessions\Application\ScheduledTask;

use App\Sessions\Application\PersonalRunAdvancerInterface;
use App\Sessions\Application\RunnerGatewayInterface;
use App\Sessions\Application\SessionReconcilerInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CleanupStaleSessionsHandler
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private SessionReconcilerInterface $sessionReconciler,
        private PersonalRunAdvancerInterface $personalRunAdvancer,
        private HubInterface $mercureHub,
        private RunnerGatewayInterface $runnerGateway,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CleanupStaleSessionsTask $task): void
    {
        $now = new \DateTimeImmutable();

        $candidates = $this->sessions->findByStatuses(Session::STALE_STATUSES);

        /** @var list<Session> $cleaned */
        $cleaned = [];

        foreach ($candidates as $session) {
            if (!$session->isStale($now)) {
                continue;
            }

            $previous = $session->getStatus();

            if (Session::STATUS_GENERATING === $previous || Session::STATUS_LAUNCHING === $previous) {
                $info = $this->runnerGateway->getSessionInfo($session->getId());

                if (null !== $info) {
                    if ('generated' === $info['status'] && Session::STATUS_GENERATING === $previous) {
                        $this->sessionReconciler->transition($session->getId(), Session::STATUS_GENERATED);
                        $this->logger->info('session.cleanup.reconciled', [
                            'sessionId' => $session->getId(),
                            'from' => $previous,
                            'to' => Session::STATUS_GENERATED,
                        ]);
                        continue;
                    }

                    if ('running' === $info['status'] && Session::STATUS_LAUNCHING === $previous && null !== $info['apPort']) {
                        $this->sessionReconciler->transitionToRunningFromOrchestrateur($session->getId(), $info['apPort'], $info['bridgePort']);
                        $this->logger->info('session.cleanup.reconciled', [
                            'sessionId' => $session->getId(),
                            'from' => $previous,
                            'to' => Session::STATUS_RUNNING,
                        ]);
                        continue;
                    }
                }
            }

            $newStatus = Session::STATUS_RUNNING === $previous
                ? Session::STATUS_CRASHED
                : Session::STATUS_FAILED;

            try {
                $session->transition($newStatus, $now);
                // A stale RUNNING session is crash-recovered to idle so the owner can resume it
                // (mirror SessionLifecycleManager's crashed->idle recovery) instead of being left
                // in a non-resumable "crashed" state; a missing save just restarts from the seed.
                // Generating/launching stay terminally failed (story 17.11).
                if (Session::STATUS_CRASHED === $newStatus) {
                    $session->markIdle($session->getLastSaveKey(), true, $now);
                    $newStatus = Session::STATUS_IDLE;
                }
            } catch (\LogicException) {
                $session->forceReset($now);
                $newStatus = Session::STATUS_STOPPED;
            }

            $this->logger->warning('session.cleanup.stale', [
                'sessionId' => $session->getId(),
                'from' => $previous,
                'to' => $newStatus,
                'runnerId' => $session->getRunnerId(),
            ]);

            if (Session::STATUS_RUNNING === $previous) {
                $this->personalRunAdvancer->markPersonalRunStopped($session->getId());

                // Only call the runner stop for runner-managed sessions.
                // Orchestrateur-managed sessions (runnerId = null) handle their own
                // container lifecycle and the bridge is already gone at this point.
                if (null !== $session->getRunnerId()) {
                    $this->runnerGateway->stopSession($session->getId());
                }
            }

            $cleaned[] = $session;
        }

        if ([] === $cleaned) {
            return;
        }

        $this->sessions->flush();

        foreach ($cleaned as $session) {
            try {
                $this->mercureHub->publish(new Update(
                    sprintf('/sessions/%s', $session->getId()),
                    json_encode($session->payload(), JSON_THROW_ON_ERROR),
                ));
            } catch (\Throwable $e) {
                $this->logger->error('session.cleanup.mercure_publish_failed', [
                    'sessionId' => $session->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('session.cleanup.done', ['cleaned' => count($cleaned)]);
    }
}
