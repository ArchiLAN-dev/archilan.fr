<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Sessions\Application\Message\ResumeRunJob;
use App\Shared\Application\Handler\LogsHandlerErrors;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Delegates resume to the bridge running inside the session container.
 * The bridge handles finding the save file, re-launching AP, and calling back
 * the /restarted endpoint when AP is healthy again (Option A: container stays alive).
 */
#[AsMessageHandler]
final readonly class ResumeRunJobHandler
{
    use LogsHandlerErrors;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $bridgeInternalToken,
    ) {
    }

    public function __invoke(ResumeRunJob $job): void
    {
        $this->logger->info('runner.resume_job.started', ['session_id' => $job->sessionId]);

        if ($job->bridgePort <= 0) {
            $this->logger->warning('runner.resume_job.no_bridge_port', ['session_id' => $job->sessionId]);

            return;
        }

        $this->executeWithLogging('runner.resume_job.bridge_call_failed', function () use ($job): void {
            $response = $this->httpClient->request(
                'POST',
                sprintf('http://localhost:%d/resume', $job->bridgePort),
                [
                    'headers' => ['Authorization' => 'Bearer '.$this->bridgeInternalToken],
                    'json' => ['lastSaveKey' => $job->lastSaveKey],
                    'timeout' => 10,
                ],
            );
            $status = $response->getStatusCode();

            if ($status >= 400) {
                $this->logger->warning('runner.resume_job.bridge_rejected', [
                    'session_id' => $job->sessionId,
                    'status' => $status,
                ]);
            } else {
                $this->logger->info('runner.resume_job.bridge_resume_triggered', [
                    'session_id' => $job->sessionId,
                ]);
            }
        });
    }
}
