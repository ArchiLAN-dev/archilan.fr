<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Http;

use Archilan\OrchestratorClient\Exception\ConflictException;
use Archilan\OrchestratorClient\Exception\OrchestratorException;
use Archilan\OrchestratorClient\Exception\ServiceUnavailableException;
use Archilan\OrchestratorClient\Exception\SessionNotFoundException;
use Archilan\OrchestratorClient\Exception\TransportException;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @internal
 *
 * Single HTTP plumbing layer: injects auth, maps errors to exceptions, decodes responses.
 * Sub-clients call this class exclusively — they contain zero raw HTTP logic.
 *
 * Adding a new endpoint group:
 *   1. Create src/Foo/FooClient.php with __construct(private readonly HttpTransport $transport)
 *   2. Add public function foo(): FooClient { return $this->fooClient; } to OrchestratorClient
 */
final class HttpTransport
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {
    }

    public function postVoid(string $path, mixed $body = []): void
    {
        try {
            $response = $this->httpClient->request('POST', $this->url($path), [
                'json' => $body,
                'headers' => ['Authorization' => 'Bearer '.$this->apiKey],
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->mapError($status, $this->tryDecodeJson($response->getContent(false)));
            }
        } catch (OrchestratorException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), $e);
        }
    }

    /** @return array<string, mixed> */
    public function postJson(string $path, mixed $body = []): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->url($path), [
                'json' => $body,
                'headers' => ['Authorization' => 'Bearer '.$this->apiKey],
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->mapError($status, $this->tryDecodeJson($response->getContent(false)));
            }

            return $this->decodeJson($response->getContent(false));
        } catch (OrchestratorException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), $e);
        }
    }

    public function postMultipartVoid(string $path, FormDataPart $form): void
    {
        try {
            $response = $this->httpClient->request('POST', $this->url($path), [
                'headers' => array_merge(
                    ['Authorization' => 'Bearer '.$this->apiKey],
                    $form->getPreparedHeaders()->toArray(),
                ),
                'body' => $form->bodyToString(),
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->mapError($status, $this->tryDecodeJson($response->getContent(false)));
            }
        } catch (OrchestratorException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), $e);
        }
    }

    /** @return array<string, mixed> */
    public function postMultipartJson(string $path, FormDataPart $form): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->url($path), [
                'headers' => array_merge(
                    ['Authorization' => 'Bearer '.$this->apiKey],
                    $form->getPreparedHeaders()->toArray(),
                ),
                'body' => $form->bodyToString(),
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->mapError($status, $this->tryDecodeJson($response->getContent(false)));
            }

            return $this->decodeJson($response->getContent(false));
        } catch (OrchestratorException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), $e);
        }
    }

    /** @return array<string, mixed> */
    public function getJson(string $path): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->url($path), [
                'headers' => ['Authorization' => 'Bearer '.$this->apiKey],
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->mapError($status, $this->tryDecodeJson($response->getContent(false)));
            }

            return $this->decodeJson($response->getContent(false));
        } catch (OrchestratorException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), $e);
        }
    }

    public function getRaw(string $path): string
    {
        try {
            $response = $this->httpClient->request('GET', $this->url($path), [
                'headers' => ['Authorization' => 'Bearer '.$this->apiKey],
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->mapError($status, $this->tryDecodeJson($response->getContent(false)));
            }

            return $response->getContent(false);
        } catch (OrchestratorException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), $e);
        }
    }

    public function deleteVoid(string $path): void
    {
        try {
            $response = $this->httpClient->request('DELETE', $this->url($path), [
                'headers' => ['Authorization' => 'Bearer '.$this->apiKey],
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->mapError($status, $this->tryDecodeJson($response->getContent(false)));
            }
        } catch (OrchestratorException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), $e);
        }
    }

    /** @param array<string, mixed> $body */
    private function mapError(int $status, array $body): never
    {
        $errorCode = is_string($body['error'] ?? null) ? $body['error'] : 'unknown';

        if (404 === $status) {
            throw new SessionNotFoundException(sprintf('Session not found: %s', $errorCode));
        }

        if (409 === $status) {
            throw new ConflictException($errorCode);
        }

        if (503 === $status) {
            throw new ServiceUnavailableException(sprintf('Service unavailable: %s', $errorCode));
        }

        throw new OrchestratorException(sprintf('HTTP %d: %s', $status, $errorCode));
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $content): array
    {
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new OrchestratorException('Invalid JSON response from orchestrateur');
        }
        $result = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function tryDecodeJson(string $content): array
    {
        try {
            return $this->decodeJson($content);
        } catch (\Throwable) {
            return [];
        }
    }
}
