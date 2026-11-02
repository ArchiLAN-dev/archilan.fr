<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Http;

use App\Wallet\Application\Exception\MinimapperzUnauthorizedException;
use App\Wallet\Application\Exception\MinimapperzUnavailableException;
use App\Wallet\Application\Port\MinimapperzClientInterface;
use App\Wallet\Application\Support\MinimapperzEvent;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimapperz's organiser API over HTTP (story 49.1). The key travels as a bearer token and never appears in an
 * exception message.
 */
final readonly class MinimapperzHttpClient implements MinimapperzClientInterface
{
    private const float TIMEOUT_SECONDS = 5.0;
    private const float MAX_DURATION_SECONDS = 10.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
    ) {
    }

    public function event(string $slug, string $apiKey): MinimapperzEvent
    {
        $data = $this->get(sprintf('/api/v1/organiser/events/%s', rawurlencode($slug)), $apiKey);

        $eventSlug = $data['slug'] ?? null;
        $name = $data['name'] ?? null;
        $opensAt = $this->date($data['opens_at'] ?? null);
        $closesAt = $this->date($data['closes_at'] ?? null);
        $isOpen = $data['is_open'] ?? null;
        if (!is_string($eventSlug) || !is_string($name) || null === $opensAt || null === $closesAt || !is_bool($isOpen)) {
            throw new MinimapperzUnavailableException('Minimapperz answered an event without its expected fields.');
        }

        return new MinimapperzEvent($eventSlug, $name, $opensAt, $closesAt, $isOpen);
    }

    /** @return array<array-key, mixed> */
    private function get(string $path, string $apiKey): array
    {
        try {
            $response = $this->httpClient->request('GET', rtrim($this->baseUrl, '/').$path, [
                'auth_bearer' => $apiKey,
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ]);
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new MinimapperzUnauthorizedException('Minimapperz refused the API key for this event.');
            }
            if (200 !== $status) {
                throw new MinimapperzUnavailableException(sprintf('Minimapperz answered HTTP %d.', $status));
            }

            return $response->toArray(false);
        } catch (MinimapperzUnauthorizedException|MinimapperzUnavailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MinimapperzUnavailableException(sprintf('Minimapperz could not be read (%s).', $e::class), 0, $e);
        }
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
