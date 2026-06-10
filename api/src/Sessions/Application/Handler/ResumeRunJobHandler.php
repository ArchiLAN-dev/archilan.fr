<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Sessions\Application\Message\ResumeRunJob;
use App\Sessions\Application\RunnerGatewayInterface;
use App\Shared\Application\Handler\LogsHandlerErrors;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Resume an idle session by asking the orchestrateur to relaunch the AP server from the save on the
 * retained session volume (epic-17 restart redesign). The orchestrateur drives the subsequent
 * session.ready / session.crashed webhooks, which move the session restarting → running / idle.
 */
#[AsMessageHandler]
final readonly class ResumeRunJobHandler
{
    use LogsHandlerErrors;

    public function __construct(
        private RunnerGatewayInterface $runnerGateway,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResumeRunJob $job): void
    {
        $this->logger->info('runner.resume_job.started', ['session_id' => $job->sessionId]);

        $this->executeWithLogging('runner.resume_job.relaunch_failed', function () use ($job): void {
            $this->runnerGateway->relaunchFromSave($job->sessionId);
            $this->logger->info('runner.resume_job.relaunch_triggered', ['session_id' => $job->sessionId]);
        });
    }
}
