<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Http;

use Archilan\BridgeClient\Exception\BridgeException;
use Archilan\BridgeClient\Exception\BridgeServiceUnavailableException;
use Archilan\BridgeClient\Exception\NotFoundException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @internal
 *
 * Single HTTP plumbing layer: injects auth, maps errors to exceptions, decodes responses.
 * Sub-clients call this class exclusively — they contain zero raw HTTP logic.
 */
final class HttpTransport
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $adminToken,
    ) {
    }

    /** @return array<string, mixed> */
    public function getJson(string $path): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->url($path), [
                'headers' => $this->headers(),
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->mapError($status, $this->tryDecodeJson($response->getContent(false)));
            }

            return $this->decodeJson($response->getContent(false));
        } catch (BridgeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BridgeException($e->getMessage(), previous: $e);
        }
    }

    public function getRaw(string $path): string
    {
        try {
            $response = $this->httpClient->request('GET', $this->url($path), [
                'headers' => $this->headers(),
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->mapError($status, $this->tryDecodeJson($response->getContent(false)));
            }

            return $response->getContent(false);
        } catch (BridgeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BridgeException($e->getMessage(), previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function postJson(string $path, array $body = []): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->url($path), [
                'json'    => $body,
                'headers' => $this->headers(),
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->mapError($status, $this->tryDecodeJson($response->getContent(false)));
            }

            return $this->decodeJson($response->getContent(false));
        } catch (BridgeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BridgeException($e->getMessage(), previous: $e);
        }
    }

    /** @param array<string, mixed> $body */
    public function postVoid(string $path, array $body = []): void
    {
        try {
            $response = $this->httpClient->request('POST', $this->url($path), [
                'json'    => $body,
                'headers' => $this->headers(),
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->mapError($status, $this->tryDecodeJson($response->getContent(false)));
            }
        } catch (BridgeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BridgeException($e->getMessage(), previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function patchJson(string $path, array $body = []): array
    {
        try {
            $response = $this->httpClient->request('PATCH', $this->url($path), [
                'json'    => $body,
                'headers' => $this->headers(),
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->mapError($status, $this->tryDecodeJson($response->getContent(false)));
            }

            return $this->decodeJson($response->getContent(false));
        } catch (BridgeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BridgeException($e->getMessage(), previous: $e);
        }
    }

    public function deleteVoid(string $path): void
    {
        try {
            $response = $this->httpClient->request('DELETE', $this->url($path), [
                'headers' => $this->headers(),
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->mapError($status, $this->tryDecodeJson($response->getContent(false)));
            }
        } catch (BridgeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BridgeException($e->getMessage(), previous: $e);
        }
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->adminToken];
    }

    /** @param array<string, mixed> $body */
    private function mapError(int $status, array $body): never
    {
        $message = is_string($body['detail'] ?? null) ? $body['detail']
            : (is_string($body['error'] ?? null) ? $body['error'] : 'unknown');

        if (404 === $status) {
            throw new NotFoundException($message);
        }

        if (503 === $status) {
            throw new BridgeServiceUnavailableException($message);
        }

        throw new BridgeException(sprintf('HTTP %d: %s', $status, $message));
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
            throw new BridgeException('Invalid JSON response from bridge');
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
