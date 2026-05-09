<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class IgdbHttpClient implements IgdbHttpClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function searchGames(string $query, int $limit = 10): array
    {
        $token = $this->getAccessToken();

        $body = sprintf(
            'fields id,name,slug,summary,cover.image_id; search "%s"; limit %d;',
            str_replace('"', '\\"', $query),
            $limit,
        );

        $response = $this->httpClient->request('POST', 'https://api.igdb.com/v4/games', [
            'headers' => [
                'Client-ID' => $this->clientId,
                'Authorization' => 'Bearer '.$token,
            ],
            'body' => $body,
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new IgdbSearchException('IGDB search failed with status '.$response->getStatusCode());
        }

        /** @var list<array<string, mixed>> $data */
        $data = $response->toArray();

        return array_map(function (array $game): array {
            $coverRaw = isset($game['cover']) && is_array($game['cover']) ? $game['cover'] : null;
            $cover = null;
            if (null !== $coverRaw) {
                $cover = [];
                foreach ($coverRaw as $k => $v) {
                    if (is_string($k)) {
                        $cover[$k] = $v;
                    }
                }
            }
            $idRaw = $game['id'] ?? 0;
            $nameRaw = $game['name'] ?? '';
            $slugRaw = $game['slug'] ?? '';

            return [
                'igdbId' => is_int($idRaw) ? $idRaw : 0,
                'name' => is_string($nameRaw) ? $nameRaw : '',
                'slug' => is_string($slugRaw) ? $slugRaw : '',
                'summary' => isset($game['summary']) && is_string($game['summary']) ? $game['summary'] : null,
                'coverUrl' => $this->coverUrl($cover),
            ];
        }, $data);
    }

    private function getAccessToken(): string
    {
        return $this->cache->get('igdb.access_token', function (ItemInterface $item): string {
            $response = $this->httpClient->request('POST', 'https://id.twitch.tv/oauth2/token', [
                'body' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'client_credentials',
                ],
            ]);

            if ($response->getStatusCode() >= 400) {
                throw new IgdbAuthException('IGDB auth failed with status '.$response->getStatusCode());
            }

            $data = $response->toArray();
            $expiresInRaw = $data['expires_in'] ?? 3600;
            $expiresIn = is_int($expiresInRaw) || is_float($expiresInRaw) || is_string($expiresInRaw) ? (int) $expiresInRaw : 3600;

            $item->expiresAfter((int) ($expiresIn * 0.9));

            $accessToken = $data['access_token'] ?? '';

            return is_string($accessToken) ? $accessToken : '';
        });
    }

    /**
     * @param array<string, mixed>|null $cover
     */
    private function coverUrl(?array $cover): ?string
    {
        if (null === $cover) {
            return null;
        }

        $imageId = $cover['image_id'] ?? null;

        if (!is_string($imageId) || '' === $imageId) {
            return null;
        }

        return sprintf('https://images.igdb.com/igdb/image/upload/t_cover_big/%s.jpg', $imageId);
    }
}
