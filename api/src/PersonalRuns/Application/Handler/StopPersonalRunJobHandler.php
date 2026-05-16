<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Handler;

use App\PersonalRuns\Application\Message\StopPersonalRunJob;
use App\PersonalRuns\Domain\Run;
use App\Sessions\Application\Message\StopRunJob;
use App\Sessions\Domain\Session;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class StopPersonalRunJobHandler
{
    use EntityFinderTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(StopPersonalRunJob $job): void
    {
        try {
            $run = $this->findOrFail(Run::class, $job->personalRunId);
        } catch (\RuntimeException) {
            $this->logger->error('personal_run.stop.not_found', ['runId' => $job->personalRunId]);

            return;
        }

        $sessionId = $run->getSessionId();
        if (null === $sessionId) {
            $this->logger->warning('personal_run.stop.no_session', ['runId' => $job->personalRunId]);

            return;
        }

        try {
            $session = $this->findOrFail(Session::class, $sessionId);
        } catch (\RuntimeException) {
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
