<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class RunnerCallbackClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $centralApiUrl,
        private string $centralApiSecret,
        private string $runnerId,
        private LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function sendCallback(string $sessionId, array $payload): void
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                rtrim($this->centralApiUrl, '/').'/api/v1/internal/sessions/'.$sessionId.'/runner-callback',
                [
                    'headers' => ['X-Internal-Secret' => $this->centralApiSecret],
                    'json' => array_merge(['runner_id' => $this->runnerId], $payload),
                ],
            );

            $statusCode = $response->getStatusCode();

            if (404 === $statusCode) {
                $this->logger->warning('runner.callback.session_not_found', [
                    'session_id' => $sessionId,
                    'runner_id' => $this->runnerId,
                    'action' => $payload['status'] ?? 'unknown',
                ]);

                return;
            }

            if ($statusCode >= 400) {
                $this->logger->error('runner.callback.failed', [
                    'session_id' => $sessionId,
                    'runner_id' => $this->runnerId,
                    'action' => $payload['status'] ?? 'unknown',
                    'http_status' => $statusCode,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('runner.callback.failed', [
                'session_id' => $sessionId,
                'runner_id' => $this->runnerId,
                'action' => $payload['status'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
