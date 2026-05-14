<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Handler;

use App\PersonalRuns\Application\Message\StopPersonalRunJob;
use App\PersonalRuns\Domain\PersonalRun;
use App\Sessions\Application\Message\StopRunJob;
use App\Sessions\Domain\Session;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class StopPersonalRunJobHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(StopPersonalRunJob $job): void
    {
        $run = $this->entityManager->find(PersonalRun::class, $job->personalRunId);

        if (!$run instanceof PersonalRun) {
            $this->logger->error('personal_run.stop.not_found', ['runId' => $job->personalRunId]);

            return;
        }

        $sessionId = $run->getSessionId();
        if (null === $sessionId) {
            $this->logger->warning('personal_run.stop.no_session', ['runId' => $job->personalRunId]);

            return;
        }

        $session = $this->entityManager->find(Session::class, $sessionId);
        if (!$session instanceof Session) {
            $this->logger->warning('personal_run.stop.session_not_found', ['runId' => $job->personalRunId, 'sessionId' => $sessionId]);

            return;
        }

        $port = $session->getPort() ?? 0;
        $bridgePort = $session->getBridgePort() ?? 0;

        $this->messageBus->dispatch(new StopRunJob($sessionId, $port, $bridgePort));

        $this->logger->info('personal_run.stop.dispatched', [
            'runId' => $job->personalRunId,
            'sessionId' => $sessionId,
        ]);
    }
}
