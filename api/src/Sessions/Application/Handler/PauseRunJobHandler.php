<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Sessions\Application\Message\PauseRunJob;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
final readonly class PauseRunJobHandler
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $bridgeInternalToken,
    ) {
    }

    public function __invoke(PauseRunJob $job): void
    {
        $this->logger->info('runner.pause_job.started', ['session_id' => $job->sessionId]);

        if ($job->bridgePort <= 0) {
            $this->logger->warning('runner.pause_job.no_bridge_port', ['session_id' => $job->sessionId]);

            return;
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                sprintf('http://localhost:%d/pause', $job->bridgePort),
                [
                    'headers' => ['Authorization' => 'Bearer '.$this->bridgeInternalToken],
                    'timeout' => 10,
                ],
            );
            $status = $response->getStatusCode();

            if ($status >= 400) {
                $this->logger->warning('runner.pause_job.bridge_rejected', [
                    'session_id' => $job->sessionId,
                    'status' => $status,
                ]);
            } else {
                $this->logger->info('runner.pause_job.bridge_pause_triggered', [
                    'session_id' => $job->sessionId,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('runner.pause_job.bridge_call_failed', [
                'session_id' => $job->sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
