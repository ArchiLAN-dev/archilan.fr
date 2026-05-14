<?php

declare(strict_types=1);

namespace App\CatalogSync\Application;

use App\GameSelection\Domain\ArchipelagoGame;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ApworldVersionChecker
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $githubToken,
    ) {
    }

    public function isAvailable(): bool
    {
        return '' !== $this->githubToken;
    }

    public function check(ArchipelagoGame $game): ?ApworldVersionInfo
    {
        $sourceUrl = $game->getApworldSourceUrl();

        if (null === $sourceUrl || !str_starts_with($sourceUrl, 'https://github.com/')) {
            return null;
        }

        if ('' === $this->githubToken) {
            $this->logger->warning('github.token_missing: GITHUB_TOKEN not configured, skipping APWorld version check');

            return null;
        }

        $pathOnly = strtok($sourceUrl, '?#') ?: $sourceUrl;
        $path = substr($pathOnly, strlen('https://github.com/'));
        $parts = explode('/', trim($path, '/'));

        if (count($parts) < 2 || '' === $parts[0] || '' === $parts[1]) {
            return null;
        }

        [$owner, $repo] = $parts;

        $githubHeaders = [
            'Authorization' => 'Bearer '.$this->githubToken,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'archilan-fr',
        ];

        // When the URL has a `q` filter (multi-game repo), search across releases
        // to find the latest one matching this specific game's .apworld asset.
        $filterTerm = null;
        $rawQuery = parse_url($sourceUrl, \PHP_URL_QUERY);
        if (is_string($rawQuery) && '' !== $rawQuery) {
            parse_str($rawQuery, $queryParams);
            $q = is_string($queryParams['q'] ?? null) ? trim($queryParams['q'], '"\'') : '';
            if ('' !== $q) {
                $filterTerm = $q;
            }
        }

        if (null !== $filterTerm) {
            $data = $this->findLatestReleaseWithAsset($owner, $repo, $filterTerm, $githubHeaders);
            if (null === $data) {
                return null;
            }
            $remaining = null;
        } else {
            $response = $this->httpClient->request(
                'GET',
                sprintf('https://api.github.com/repos/%s/%s/releases/latest', $owner, $repo),
                ['headers' => $githubHeaders],
            );

            if ($response->getStatusCode() >= 400) {
                return null;
            }

            $responseHeaders = $response->getHeaders();
            $remaining = isset($responseHeaders['x-ratelimit-remaining'][0]) ? (int) $responseHeaders['x-ratelimit-remaining'][0] : null;

            /** @var array<string, mixed> $data */
            $data = $response->toArray();
        }

        if (null !== $remaining) {
            $this->logger->info('github.rate_limit_remaining', ['remaining' => $remaining]);
        }

        $tagRaw = is_string($data['tag_name'] ?? null) ? (string) $data['tag_name'] : '';
        $normalizedTag = ltrim($tagRaw, 'vV');

        $publishedAtRaw = is_string($data['published_at'] ?? null) ? (string) $data['published_at'] : 'now';
        $publishedAt = new \DateTimeImmutable($publishedAtRaw);

        $releaseUrl = is_string($data['html_url'] ?? null) ? (string) $data['html_url'] : '';

        $assetName = null;
        $assetDownloadUrl = null;
        $assets = is_array($data['assets'] ?? null) ? $data['assets'] : [];
        foreach ($assets as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null) && str_ends_with((string) $asset['name'], '.apworld')) {
                $assetName = (string) $asset['name'];
                $assetDownloadUrl = is_string($asset['browser_download_url'] ?? null) ? (string) $asset['browser_download_url'] : null;
                break;
            }
        }

        $game->recordApworldCheck($normalizedTag, $publishedAt, $releaseUrl);

        $deployedVersion = $game->getApworldDeployedVersion();
        if (null === $deployedVersion) {
            $updateStatus = ArchipelagoGame::UPDATE_STATUS_UPDATE_AVAILABLE;
        } else {
            $normalizedDeployed = ltrim($deployedVersion, 'vV');
            $updateStatus = $normalizedTag === $normalizedDeployed
                ? ArchipelagoGame::UPDATE_STATUS_UP_TO_DATE
                : ArchipelagoGame::UPDATE_STATUS_UPDATE_AVAILABLE;
        }

        $info = new ApworldVersionInfo(
            latestTag: $normalizedTag,
            publishedAt: $publishedAt,
            releaseUrl: $releaseUrl,
            assetName: $assetName,
            assetDownloadUrl: $assetDownloadUrl,
            updateStatus: $updateStatus,
            isNewer: ArchipelagoGame::UPDATE_STATUS_UPDATE_AVAILABLE === $updateStatus,
        );

        if (null !== $remaining && $remaining <= 10) {
            $this->logger->warning('github.rate_limit_low', ['remaining' => $remaining]);
            throw new GithubRateLimitException(sprintf('GitHub API rate limit low: %d requests remaining', $remaining));
        }

        return $info;
    }

    /**
     * Return all .apworld assets from the latest matching release.
     *
     * @return list<array{name: string, downloadUrl: string, size: int}>|null null = release/repo unreachable
     */
    public function listAssets(ArchipelagoGame $game): ?array
    {
        $sourceUrl = $game->getApworldSourceUrl();

        if (null === $sourceUrl || !str_starts_with($sourceUrl, 'https://github.com/')) {
            return null;
        }

        if ('' === $this->githubToken) {
            return null;
        }

        $pathOnly = strtok($sourceUrl, '?#') ?: $sourceUrl;
        $path = substr($pathOnly, strlen('https://github.com/'));
        $parts = explode('/', trim($path, '/'));

        if (count($parts) < 2 || '' === $parts[0] || '' === $parts[1]) {
            return null;
        }

        [$owner, $repo] = $parts;

        $githubHeaders = [
            'Authorization' => 'Bearer '.$this->githubToken,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'archilan-fr',
        ];

        $filterTerm = null;
        $rawQuery = parse_url($sourceUrl, \PHP_URL_QUERY);
        if (is_string($rawQuery) && '' !== $rawQuery) {
            parse_str($rawQuery, $queryParams);
            $q = is_string($queryParams['q'] ?? null) ? trim($queryParams['q'], '"\'') : '';
            if ('' !== $q) {
                $filterTerm = $q;
            }
        }

        if (null !== $filterTerm) {
            $releaseData = $this->findLatestReleaseWithAsset($owner, $repo, $filterTerm, $githubHeaders);
        } else {
            $response = $this->httpClient->request(
                'GET',
                sprintf('https://api.github.com/repos/%s/%s/releases/latest', $owner, $repo),
                ['headers' => $githubHeaders],
            );

            if ($response->getStatusCode() >= 400) {
                return null;
            }

            /** @var array<string, mixed> $releaseData */
            $releaseData = $response->toArray();
        }

        if (null === $releaseData) {
            return null;
        }

        $assets = is_array($releaseData['assets'] ?? null) ? $releaseData['assets'] : [];
        $result = [];

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $name = is_string($asset['name'] ?? null) ? (string) $asset['name'] : '';
            $downloadUrl = is_string($asset['browser_download_url'] ?? null) ? (string) $asset['browser_download_url'] : '';
            $size = is_int($asset['size'] ?? null) ? (int) $asset['size'] : 0;

            if (str_ends_with($name, '.apworld') && '' !== $downloadUrl) {
                $result[] = ['name' => $name, 'downloadUrl' => $downloadUrl, 'size' => $size];
            }
        }

        return $result;
    }

    /**
     * Download the raw bytes of a GitHub release asset.
     * Uses a token-authenticated request so private/rate-limited repos work.
     */
    public function downloadAsset(string $downloadUrl): string
    {
        $response = $this->httpClient->request('GET', $downloadUrl, [
            'headers' => [
                'Authorization' => 'Bearer '.$this->githubToken,
                'Accept' => 'application/octet-stream',
                'User-Agent' => 'archilan-fr',
            ],
            'max_redirects' => 5,
        ]);

        return $response->getContent();
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>|null
     */
    private function findLatestReleaseWithAsset(string $owner, string $repo, string $filterTerm, array $headers): ?array
    {
        for ($page = 1; $page <= 10; ++$page) {
            $response = $this->httpClient->request(
                'GET',
                sprintf('https://api.github.com/repos/%s/%s/releases?per_page=100&page=%d', $owner, $repo, $page),
                ['headers' => $headers],
            );

            if ($response->getStatusCode() >= 400) {
                return null;
            }

            /** @var list<array<string, mixed>> $releases */
            $releases = $response->toArray();

            if ([] === $releases) {
                return null;
            }

            foreach ($releases as $release) {
                $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];
                foreach ($assets as $asset) {
                    if (is_array($asset)
                        && is_string($asset['name'] ?? null)
                        && str_ends_with((string) $asset['name'], '.apworld')
                        && false !== stripos((string) $asset['name'], $filterTerm)) {
                        return $release;
                    }
                }
            }

            if (\count($releases) < 100) {
                return null;
            }
        }

        return null;
    }
}
