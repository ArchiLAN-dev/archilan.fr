<?php

declare(strict_types=1);

namespace App\Streaming\Infrastructure;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TwitchApiClient implements TwitchApiClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $channelLogin,
    ) {
    }

    public function fetchViewerCount(): ?int
    {
        if ('' === $this->clientId || '' === $this->clientSecret || '' === $this->channelLogin) {
            return null;
        }

        try {
            $token = $this->getAppToken();

            $response = $this->httpClient->request('GET', 'https://api.twitch.tv/helix/streams', [
                'headers' => [
                    'Client-Id' => $this->clientId,
                    'Authorization' => 'Bearer '.$token,
                ],
                'query' => ['user_login' => $this->channelLogin],
            ]);

            $data = $response->toArray();

            /** @var list<array{viewer_count?: int}> $streams */
            $streams = $data['data'] ?? [];

            if ([] !== $streams && isset($streams[0]['viewer_count'])) {
                return (int) $streams[0]['viewer_count'];
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function getAppToken(): string
    {
        return $this->cache->get('streaming.twitch_app_token', function (ItemInterface $item): string {
            $response = $this->httpClient->request('POST', 'https://id.twitch.tv/oauth2/token', [
                'body' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'client_credentials',
                ],
            ]);

            $data = $response->toArray();
            $expiresInRaw = $data['expires_in'] ?? 3600;
            $expiresIn = is_int($expiresInRaw) || is_float($expiresInRaw) || is_string($expiresInRaw) ? (int) $expiresInRaw : 3600;

            // Cache for 90 % of the actual token lifetime to avoid a race at expiry
            $item->expiresAfter((int) ($expiresIn * 0.9));

            $accessToken = $data['access_token'] ?? '';

            return is_string($accessToken) ? $accessToken : '';
        });
    }
}
