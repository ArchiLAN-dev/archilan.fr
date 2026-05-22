<?php

declare(strict_types=1);

namespace App\Sessions\Application\ScheduledTask;

use App\Sessions\Application\Message\PauseRunJob;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class InactivityWatchdogHandler
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
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

        $allRunning = $this->sessions->findByStatus(Session::STATUS_RUNNING);
        /** @var list<Session> $sessions */
        $sessions = array_values(array_filter(
            $allRunning,
            fn (Session $s): bool => null !== $s->getStartedAt()
                && $s->getStartedAt() < $graceLimit
                && (null === $s->getLastActivityAt() || $s->getLastActivityAt() < $threshold),
        ));

        foreach ($sessions as $session) {
            $this->messageBus->dispatch(new PauseRunJob($session->getId(), $session->getBridgePort() ?? 0));
            $this->logger->info('watchdog.pause_dispatched', ['sessionId' => $session->getId()]);
        }

        $this->logger->info('watchdog.done', ['checked' => count($sessions)]);
    }
}
