<?php

declare(strict_types=1);

namespace App\Sessions\Application\ScheduledTask;

use App\Sessions\Application\SessionReconcilerInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\Sessions\Infrastructure\RunnerGatewayInterface;
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

                    if ('running' === $info['status'] && Session::STATUS_LAUNCHING === $previous && null !== $info['bridgePort']) {
                        $this->sessionReconciler->transitionToRunningFromOrchestrateur($session->getId(), $info['bridgePort']);
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
                $this->runnerGateway->stopSession($session->getId());
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
