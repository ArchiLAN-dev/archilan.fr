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

    public function fetchLiveLogins(array $logins): array
    {
        if ('' === $this->clientId || '' === $this->clientSecret || [] === $logins) {
            return [];
        }

        try {
            $token = $this->getAppToken();
        } catch (\Throwable) {
            return [];
        }

        $live = [];
        // Helix /streams accepts up to 100 user_login params per call.
        foreach (array_chunk(array_values(array_unique($logins)), 100) as $chunk) {
            // Twitch needs repeated `user_login=` params; Symfony's array query encoding would emit
            // `user_login[0]=`, so build the query string explicitly.
            $query = implode('&', array_map(
                static fn (string $login): string => 'user_login='.rawurlencode($login),
                $chunk,
            ));

            try {
                $response = $this->httpClient->request('GET', 'https://api.twitch.tv/helix/streams?'.$query, [
                    'headers' => [
                        'Client-Id' => $this->clientId,
                        'Authorization' => 'Bearer '.$token,
                    ],
                ]);

                $data = $response->toArray();

                $streams = is_array($data['data'] ?? null) ? $data['data'] : [];

                foreach ($streams as $stream) {
                    if (!is_array($stream)) {
                        continue;
                    }
                    $login = $stream['user_login'] ?? null;
                    if (is_string($login) && '' !== $login) {
                        $viewerCount = $stream['viewer_count'] ?? null;
                        $live[mb_strtolower($login)] = is_int($viewerCount) ? $viewerCount : 0;
                    }
                }
            } catch (\Throwable) {
                // Tolerate a failed chunk - keep whatever the other chunks returned.
                continue;
            }
        }

        return $live;
    }

    public function fetchAvatars(array $logins): array
    {
        if ('' === $this->clientId || '' === $this->clientSecret || [] === $logins) {
            return [];
        }

        try {
            $token = $this->getAppToken();
        } catch (\Throwable) {
            return [];
        }

        $avatars = [];
        // Helix /users accepts up to 100 login params per call.
        foreach (array_chunk(array_values(array_unique($logins)), 100) as $chunk) {
            $query = implode('&', array_map(
                static fn (string $login): string => 'login='.rawurlencode($login),
                $chunk,
            ));

            try {
                $response = $this->httpClient->request('GET', 'https://api.twitch.tv/helix/users?'.$query, [
                    'headers' => [
                        'Client-Id' => $this->clientId,
                        'Authorization' => 'Bearer '.$token,
                    ],
                ]);

                $data = $response->toArray();
                $users = is_array($data['data'] ?? null) ? $data['data'] : [];

                foreach ($users as $user) {
                    if (!is_array($user)) {
                        continue;
                    }
                    $login = $user['login'] ?? null;
                    $avatar = $user['profile_image_url'] ?? null;
                    if (is_string($login) && '' !== $login && is_string($avatar) && '' !== $avatar) {
                        $avatars[mb_strtolower($login)] = $avatar;
                    }
                }
            } catch (\Throwable) {
                // Tolerate a failed chunk - keep whatever the other chunks returned.
                continue;
            }
        }

        return $avatars;
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
