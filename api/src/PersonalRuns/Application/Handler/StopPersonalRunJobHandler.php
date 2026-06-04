<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Handler;

use App\PersonalRuns\Application\Message\StopPersonalRunJob;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Sessions\Application\RunnerGatewayInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class StopPersonalRunJobHandler
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private SessionRepositoryInterface $sessions,
        private RunnerGatewayInterface $runnerGateway,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(StopPersonalRunJob $job): void
    {
        $run = $this->runs->findById($job->personalRunId);
        if (!$run instanceof Run) {
            $this->logger->error('personal_run.stop.not_found', ['runId' => $job->personalRunId]);

            return;
        }

        $sessionId = $run->getSessionId();
        if (null === $sessionId) {
            $this->logger->warning('personal_run.stop.no_session', ['runId' => $job->personalRunId]);

            return;
        }

        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            $this->logger->warning('personal_run.stop.session_not_found', ['runId' => $job->personalRunId, 'sessionId' => $sessionId]);

            return;
        }

        $this->runnerGateway->stopSession($sessionId);

        $this->logger->info('personal_run.stop.dispatched', [
            'runId' => $job->personalRunId,
            'sessionId' => $sessionId,
        ]);
    }
}
