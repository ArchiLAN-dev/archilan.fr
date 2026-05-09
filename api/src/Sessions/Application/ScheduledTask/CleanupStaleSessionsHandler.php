<?php

declare(strict_types=1);

namespace App\Sessions\Application\ScheduledTask;

use App\Sessions\Application\Message\StopRunJob;
use App\Sessions\Domain\Session;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class CleanupStaleSessionsHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HubInterface $mercureHub,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CleanupStaleSessionsTask $task): void
    {
        $now = new \DateTimeImmutable();

        /** @var list<Session> $candidates */
        $candidates = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Session::class, 's')
            ->where('s.status IN (:statuses)')
            ->setParameter('statuses', Session::STALE_STATUSES)
            ->getQuery()
            ->getResult();

        /** @var list<Session> $cleaned */
        $cleaned = [];

        foreach ($candidates as $session) {
            if (!$session->isStale($now)) {
                continue;
            }

            $previous = $session->getStatus();
            $newStatus = Session::STATUS_RUNNING === $previous
                ? Session::STATUS_CRASHED
                : Session::STATUS_FAILED;

            $port = $session->getPort() ?? 0;
            $bridgePort = $session->getBridgePort() ?? 0;

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

            // Running containers need an explicit stop so the runner cleans up Docker and releases ports.
            if (Session::STATUS_RUNNING === $previous) {
                $this->messageBus->dispatch(new StopRunJob($session->getId(), $port, $bridgePort));
            }

            $cleaned[] = $session;
        }

        if ([] === $cleaned) {
            return;
        }

        $this->entityManager->flush();

        foreach ($cleaned as $session) {
            try {
                $this->mercureHub->publish(new Update(
                    sprintf('/sessions/%s', $session->getId()),
                    json_encode($session->payload(), JSON_THROW_ON_ERROR),
                ));
            } catch (\Throwable) {
            }
        }

        $this->logger->info('session.cleanup.done', ['cleaned' => count($cleaned)]);
    }
}
