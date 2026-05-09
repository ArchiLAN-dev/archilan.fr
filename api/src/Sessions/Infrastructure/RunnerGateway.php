<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class RunnerGateway implements RunnerGatewayInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $runnerBaseUrl,
        private string $runnerApiKey,
        private LoggerInterface $logger,
    ) {
    }

    public function uploadApworld(string $fileContents, string $filename): array
    {
        try {
            $formData = new FormDataPart(['file' => new DataPart($fileContents, $filename, 'application/octet-stream')]);
            $response = $this->httpClient->request('POST', $this->url('/apworld/upload'), [
                'headers' => array_merge(['x-api-key' => $this->runnerApiKey], $formData->getPreparedHeaders()->toArray()),
                'body' => $formData->bodyToString(),
                'timeout' => 90,
            ]);

            return $this->toStringKeyedArray($response->toArray(false));
        } catch (\Throwable $e) {
            $this->logger->error('runner.request_failed', ['path' => '/apworld/upload', 'method' => 'POST', 'error' => $e->getMessage()]);

            return ['error' => 'runner_unavailable'];
        }
    }

    public function preflight(string $sessionId, array $slots): array
    {
        return $this->post("/sessions/{$sessionId}/preflight", ['slots' => $slots]);
    }

    public function writeYamls(string $sessionId, array $slots): array
    {
        return $this->post("/sessions/{$sessionId}/yamls", ['slots' => $slots]);
    }

    public function generate(string $sessionId): array
    {
        return $this->post("/sessions/{$sessionId}/generate", []);
    }

    public function launch(string $sessionId): array
    {
        return $this->post("/sessions/{$sessionId}/launch", []);
    }

    public function restart(string $sessionId): array
    {
        return $this->post("/sessions/{$sessionId}/restart", []);
    }

    public function stop(string $sessionId): array
    {
        return $this->delete("/sessions/{$sessionId}");
    }

    public function getYamlsZip(string $sessionId): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $this->url("/sessions/{$sessionId}/yamls.zip"), [
                'headers' => ['x-api-key' => $this->runnerApiKey],
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            return $response->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->error('runner.request_failed', ['sessionId' => $sessionId, 'path' => 'yamls.zip', 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->url($path), [
                'json' => $body,
                'headers' => ['x-api-key' => $this->runnerApiKey],
            ]);

            return $this->toStringKeyedArray($response->toArray(false));
        } catch (\Throwable $e) {
            $this->logger->error('runner.request_failed', ['path' => $path, 'method' => 'POST', 'error' => $e->getMessage()]);

            return ['error' => 'runner_unavailable'];
        }
    }

    /** @return array<string, mixed> */
    private function delete(string $path): array
    {
        try {
            $response = $this->httpClient->request('DELETE', $this->url($path), [
                'headers' => ['x-api-key' => $this->runnerApiKey],
            ]);

            return $this->toStringKeyedArray($response->toArray(false));
        } catch (\Throwable $e) {
            $this->logger->error('runner.request_failed', ['path' => $path, 'method' => 'DELETE', 'error' => $e->getMessage()]);

            return ['error' => 'runner_unavailable'];
        }
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function toStringKeyedArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function url(string $path): string
    {
        return rtrim($this->runnerBaseUrl, '/').$path;
    }
}
