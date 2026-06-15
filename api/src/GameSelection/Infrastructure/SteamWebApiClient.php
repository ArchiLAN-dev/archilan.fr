<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SteamWebApiClient implements SteamWebApiClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
    ) {
    }

    public function resolveVanityUrl(string $vanity): ?string
    {
        if ('' === $this->apiKey) {
            return null;
        }

        $data = $this->get('https://api.steampowered.com/ISteamUser/ResolveVanityURL/v1/', [
            'key' => $this->apiKey,
            'vanityurl' => $vanity,
        ]);

        $response = $data['response'] ?? null;
        if (!is_array($response)) {
            return null;
        }

        $steamId = $response['steamid'] ?? null;

        if (1 === ($response['success'] ?? null) && is_string($steamId) && '' !== $steamId) {
            return $steamId;
        }

        return null;
    }

    public function fetchOwnedAppIds(string $steamId64): array
    {
        if ('' === $this->apiKey) {
            return ['visibility' => 'private', 'appIds' => []];
        }

        $data = $this->get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v1/', [
            'key' => $this->apiKey,
            'steamid' => $steamId64,
            'include_appinfo' => 0,
            'format' => 'json',
        ]);

        $response = $data['response'] ?? null;
        if (!is_array($response) || !isset($response['games']) || !is_array($response['games'])) {
            return ['visibility' => 'private', 'appIds' => []];
        }

        $appIds = [];
        foreach ($response['games'] as $game) {
            if (!is_array($game)) {
                continue;
            }
            $appId = $game['appid'] ?? null;
            if (is_int($appId)) {
                $appIds[] = $appId;
            } elseif (is_string($appId) && 1 === preg_match('/^\d+$/', $appId)) {
                $appIds[] = (int) $appId;
            }
        }

        return ['visibility' => 'public', 'appIds' => $appIds];
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<array-key, mixed>
     */
    private function get(string $url, array $query): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, ['query' => $query]);
            $status = $response->getStatusCode();
            // false = do not throw on non-2xx; we surface a typed exception ourselves.
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new SteamApiException('Steam API request failed: '.$e->getMessage(), 0, $e);
        }

        if ($status >= 400) {
            throw new SteamApiException('Steam API returned status '.$status);
        }

        return $data;
    }
}
