<?php

declare(strict_types=1);

namespace App\CatalogSync\Application;

use App\GameSelection\Domain\Game;
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

    public function isDirectApworldUrl(string $url): bool
    {
        $path = parse_url($url, \PHP_URL_PATH);

        return is_string($path) && str_ends_with(strtolower($path), '.apworld');
    }

    public function check(Game $game): ?ApworldVersionInfo
    {
        $sourceUrl = $game->getApworldSourceUrl();

        if (null === $sourceUrl) {
            return null;
        }

        // Direct .apworld file URLs have no version tracking
        if ($this->isDirectApworldUrl($sourceUrl)) {
            return null;
        }

        if (!str_starts_with($sourceUrl, 'https://github.com/')) {
            return null;
        }

        if ('' === $this->githubToken) {
            $this->logger->warning('github.token_missing: GITHUB_TOKEN not configured, skipping APWorld version check');

            return null;
        }

        [$owner, $repo, $filterTerm] = $this->parseSourceUrl($sourceUrl);

        if (null === $owner) {
            return null;
        }

        $githubHeaders = $this->githubHeaders();

        ['release' => $data, 'remaining' => $remaining] = $this->findLatestReleaseWithApworld($owner, $repo, $filterTerm, $githubHeaders);

        if (null === $data) {
            return null;
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
            $updateStatus = Game::UPDATE_STATUS_UNKNOWN;
        } else {
            $normalizedDeployed = ltrim($deployedVersion, 'vV');
            $updateStatus = $normalizedTag === $normalizedDeployed
                ? Game::UPDATE_STATUS_UP_TO_DATE
                : Game::UPDATE_STATUS_UPDATE_AVAILABLE;
        }

        $info = new ApworldVersionInfo(
            latestTag: $normalizedTag,
            publishedAt: $publishedAt,
            releaseUrl: $releaseUrl,
            assetName: $assetName,
            assetDownloadUrl: $assetDownloadUrl,
            updateStatus: $updateStatus,
            isNewer: Game::UPDATE_STATUS_UPDATE_AVAILABLE === $updateStatus,
        );

        // Rate limit check after persisting the version — mirrors the old behaviour where
        // the game state was recorded before the exception was raised.
        $this->checkRateLimit($remaining);

        return $info;
    }

    /**
     * Return all .apworld assets from the latest matching release.
     *
     * @return list<array{name: string, downloadUrl: string, size: int}>|null null = release/repo unreachable
     */
    public function listAssets(Game $game): ?array
    {
        $sourceUrl = $game->getApworldSourceUrl();

        if (null === $sourceUrl) {
            return null;
        }

        // Direct .apworld file URL — no API call needed.
        // Normalize blob → raw to ensure the download URL serves the binary.
        if ($this->isDirectApworldUrl($sourceUrl)) {
            $downloadUrl = Game::normalizeApworldSourceUrl($sourceUrl) ?? $sourceUrl;
            $rawPath = parse_url($downloadUrl, \PHP_URL_PATH);
            $filename = is_string($rawPath) ? basename($rawPath) : 'apworld.apworld';

            return [['name' => $filename, 'downloadUrl' => $downloadUrl, 'size' => 0]];
        }

        if (!str_starts_with($sourceUrl, 'https://github.com/')) {
            return null;
        }

        if ('' === $this->githubToken) {
            return null;
        }

        [$owner, $repo, $filterTerm] = $this->parseSourceUrl($sourceUrl);

        if (null === $owner) {
            return null;
        }

        ['release' => $releaseData] = $this->findLatestReleaseWithApworld($owner, $repo, $filterTerm, $this->githubHeaders());

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
        $headers = ['User-Agent' => 'archilan-fr'];

        if (str_contains($downloadUrl, 'github.com') || str_contains($downloadUrl, 'githubusercontent.com')) {
            $headers['Authorization'] = 'Bearer '.$this->githubToken;
            $headers['Accept'] = 'application/octet-stream';
        }

        $response = $this->httpClient->request('GET', $downloadUrl, [
            'headers' => $headers,
            'max_redirects' => 5,
        ]);

        return $response->getContent();
    }

    /**
     * Parse a GitHub source URL into [owner, repo, filterTerm|null].
     * Returns [null, '', null] when the URL is malformed.
     *
     * @return array{0: string|null, 1: string, 2: string|null}
     */
    private function parseSourceUrl(string $sourceUrl): array
    {
        $pathOnly = strtok($sourceUrl, '?#') ?: $sourceUrl;
        $path = substr($pathOnly, strlen('https://github.com/'));
        $parts = explode('/', trim($path, '/'));

        if (count($parts) < 2 || '' === $parts[0] || '' === $parts[1]) {
            return [null, '', null];
        }

        $owner = $parts[0];
        $repo = $parts[1];

        $filterTerm = null;
        $rawQuery = parse_url($sourceUrl, \PHP_URL_QUERY);
        if (is_string($rawQuery) && '' !== $rawQuery) {
            parse_str($rawQuery, $queryParams);
            $q = is_string($queryParams['q'] ?? null) ? trim($queryParams['q'], '"\'') : '';
            if ('' !== $q) {
                $filterTerm = $q;
            }
        }

        return [$owner, $repo, $filterTerm];
    }

    /**
     * @return array<string, string>
     */
    private function githubHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->githubToken,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'archilan-fr',
        ];
    }

    /**
     * Search releases from newest to oldest for the first non-draft release
     * that contains an .apworld asset. When $filterTerm is provided, the release
     * name/tag OR the asset filename must contain that term (case-insensitive).
     *
     * Returns ['release' => array|null, 'remaining' => int|null].
     * The rate limit remaining is taken from the last response read; the caller
     * is responsible for throwing GithubRateLimitException if needed.
     *
     * @param array<string, string> $headers
     *
     * @return array{release: array<string, mixed>|null, remaining: int|null}
     */
    private function findLatestReleaseWithApworld(string $owner, string $repo, ?string $filterTerm, array $headers): array
    {
        $remaining = null;

        for ($page = 1; $page <= 10; ++$page) {
            $response = $this->httpClient->request(
                'GET',
                sprintf('https://api.github.com/repos/%s/%s/releases?per_page=100&page=%d', $owner, $repo, $page),
                ['headers' => $headers],
            );

            if ($response->getStatusCode() >= 400) {
                return ['release' => null, 'remaining' => $remaining];
            }

            $responseHeaders = $response->getHeaders();
            $remaining = isset($responseHeaders['x-ratelimit-remaining'][0]) ? (int) $responseHeaders['x-ratelimit-remaining'][0] : $remaining;

            if (null !== $remaining) {
                $this->logger->info('github.rate_limit_remaining', ['remaining' => $remaining]);
            }

            /** @var list<array<string, mixed>> $releases */
            $releases = $response->toArray();

            if ([] === $releases) {
                return ['release' => null, 'remaining' => $remaining];
            }

            foreach ($releases as $release) {
                if (true === ($release['draft'] ?? false)) {
                    continue;
                }

                $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];

                foreach ($assets as $asset) {
                    if (!is_array($asset) || !is_string($asset['name'] ?? null)) {
                        continue;
                    }

                    $assetName = (string) $asset['name'];

                    if (!str_ends_with($assetName, '.apworld')) {
                        continue;
                    }

                    if (null === $filterTerm) {
                        return ['release' => $release, 'remaining' => $remaining];
                    }

                    $releaseName = is_string($release['name'] ?? null) ? (string) $release['name'] : '';
                    $releaseTag = is_string($release['tag_name'] ?? null) ? (string) $release['tag_name'] : '';
                    $releaseMatches = false !== stripos($releaseName, $filterTerm)
                        || false !== stripos($releaseTag, $filterTerm);

                    if ($releaseMatches || false !== stripos($assetName, $filterTerm)) {
                        return ['release' => $release, 'remaining' => $remaining];
                    }
                }
            }

            if (\count($releases) < 100) {
                return ['release' => null, 'remaining' => $remaining];
            }
        }

        return ['release' => null, 'remaining' => $remaining];
    }

    private function checkRateLimit(?int $remaining): void
    {
        if (null === $remaining || $remaining > 10) {
            return;
        }

        $this->logger->warning('github.rate_limit_low', ['remaining' => $remaining]);
        throw new GithubRateLimitException(sprintf('GitHub API rate limit low: %d requests remaining', $remaining));
    }
}
