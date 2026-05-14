<?php

declare(strict_types=1);

namespace App\Sessions\Application\ScheduledTask;

use App\Sessions\Application\Message\PauseRunJob;
use App\Sessions\Domain\Session;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class InactivityWatchdogHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private int $inactivityTimeoutSeconds,
    ) {
    }

    public function __invoke(InactivityWatchdogMessage $message): void
    {
        $now = new \DateTimeImmutable();
        $threshold = $now->modify("-{$this->inactivityTimeoutSeconds} seconds");
        $graceLimit = $now->modify('-60 seconds');

        /** @var list<Session> $sessions */
        $sessions = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Session::class, 's')
            ->where('s.status = :status')
            ->andWhere('s.startedAt < :graceLimit')
            ->andWhere('(s.lastActivityAt IS NULL OR s.lastActivityAt < :threshold)')
            ->setParameter('status', Session::STATUS_RUNNING)
            ->setParameter('threshold', $threshold)
            ->setParameter('graceLimit', $graceLimit)
            ->getQuery()
            ->getResult();

        foreach ($sessions as $session) {
            $this->messageBus->dispatch(new PauseRunJob($session->getId(), $session->getBridgePort() ?? 0));
            $this->logger->info('watchdog.pause_dispatched', ['sessionId' => $session->getId()]);
        }

        $this->logger->info('watchdog.done', ['checked' => count($sessions)]);
    }
}
