<?php

declare(strict_types=1);

namespace App\Trackers\Infrastructure\Http;

use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Exception\ValidationException;
use App\Trackers\Application\Port\TrackerPackFetcherInterface;
use App\Trackers\Application\Port\TrackerPackUpdateSourceInterface;
use App\Trackers\Application\Support\FetchedTrackerPack;
use App\Trackers\Domain\Entity\TrackerPackVersion;
use App\Trackers\Domain\Enum\TrackerPackSourceKind;
use App\Trackers\Domain\Service\TolerantJson;
use App\Trackers\Domain\Service\TrackerPackVersions;
use App\Trackers\Domain\ValueObject\TrackerPackSource;
use App\Trackers\Domain\ValueObject\TrackerPackUpdate;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Downloads a PopTracker pack from GitHub (story 54.1). Redirects are followed by hand so that only GitHub hosts are
 * ever contacted, and the download stops as soon as it passes the size cap. The GitHub token, when configured, is
 * only sent to the API host: release asset redirects land on signed storage URLs that refuse extra credentials.
 *
 * Story 54.3: the same rules read a pack's `versions.json` and its latest release, for the update check.
 */
final readonly class GithubTrackerPackFetcher implements TrackerPackFetcherInterface, TrackerPackUpdateSourceInterface
{
    private const string API = 'https://api.github.com';

    private const array ALLOWED_HOSTS = [
        'github.com',
        'api.github.com',
        'codeload.github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
        'raw.githubusercontent.com',
    ];

    private const int MAX_REDIRECTS = 5;
    private const int MAX_VERSIONS_FILE_BYTES = 1024 * 1024;
    private const float MAX_DURATION = 60.0;
    private const string TOO_LARGE = 'Le pack dépasse la taille autorisée (50 Mo compressé, 200 Mo décompressé, 5000 fichiers).';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $githubToken,
    ) {
    }

    public function fetch(TrackerPackSource $source): FetchedTrackerPack
    {
        if (TrackerPackSourceKind::Zip === $source->kind && null !== $source->zipUrl) {
            $downloadUrl = $source->zipUrl;
            $tag = $source->tag;
        } else {
            [$downloadUrl, $tag] = $this->fromRelease($source);
        }

        return new FetchedTrackerPack($this->download($downloadUrl), $downloadUrl, $tag, $this->license($source));
    }

    public function fetchZip(string $url): string
    {
        return $this->download($url);
    }

    public function fromVersionsFile(string $versionsUrl): ?TrackerPackUpdate
    {
        $body = $this->download($versionsUrl, self::MAX_VERSIONS_FILE_BYTES);
        try {
            return TrackerPackVersions::fromVersionsFile(TolerantJson::decode($body));
        } catch (\DomainException) {
            throw self::refused('tracker_pack_versions_file_invalid', 'Le fichier versions.json du pack est illisible.');
        }
    }

    public function fromLatestRelease(TrackerPackSource $source): ?TrackerPackUpdate
    {
        $release = $this->api(sprintf('/repos/%s/%s/releases/latest', $source->owner, $source->repo));
        $tag = $release['tag_name'] ?? null;
        if (null === $release || !is_string($tag) || '' === $tag) {
            return null;
        }
        $body = $release['body'] ?? null;

        return new TrackerPackUpdate(
            TrackerPackVersions::fromTag($tag),
            self::pickZipAsset($release['assets'] ?? []) ?? TrackerPackSource::tagArchiveUrl($source->owner, $source->repo, $tag),
            null,
            is_string($body) ? TrackerPackUpdate::changelogFromText($body) : [],
            mb_substr($tag, 0, 120),
        );
    }

    /** @return array{0: string, 1: string} the zip to download and the release tag */
    private function fromRelease(TrackerPackSource $source): array
    {
        $path = TrackerPackSourceKind::Release === $source->kind && null !== $source->tag
            ? sprintf('/repos/%s/%s/releases/tags/%s', $source->owner, $source->repo, rawurlencode($source->tag))
            : sprintf('/repos/%s/%s/releases/latest', $source->owner, $source->repo);
        $release = $this->api($path);
        if (null === $release) {
            throw self::refused('tracker_pack_release_not_found', 'Aucune release publiée à ce lien : vérifie le dépôt ou colle le lien d\'un zip.');
        }
        $tag = $release['tag_name'] ?? null;
        if (!is_string($tag) || '' === $tag) {
            throw self::refused('tracker_pack_release_not_found', 'Aucune release publiée à ce lien : vérifie le dépôt ou colle le lien d\'un zip.');
        }

        $asset = self::pickZipAsset($release['assets'] ?? []);

        return [$asset ?? TrackerPackSource::tagArchiveUrl($source->owner, $source->repo, $tag), $tag];
    }

    /**
     * One zip asset: that one. Several: the first whose name speaks of a pack, else the first. None: null (the
     * caller falls back to the tag's source archive, which is what pack authors tell players to download).
     */
    private static function pickZipAsset(mixed $assets): ?string
    {
        if (!is_array($assets)) {
            return null;
        }
        $zips = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = $asset['name'] ?? null;
            $url = $asset['browser_download_url'] ?? null;
            if (is_string($name) && is_string($url) && str_ends_with(strtolower($name), '.zip')) {
                $zips[] = ['name' => strtolower($name), 'url' => $url];
            }
        }
        if ([] === $zips) {
            return null;
        }
        foreach ($zips as $zip) {
            if (str_contains($zip['name'], 'poptracker') || str_contains($zip['name'], 'pack')) {
                return $zip['url'];
            }
        }

        return $zips[0]['url'];
    }

    /** The SPDX id GitHub detected for the repository, or null when unknown or unreadable. */
    private function license(TrackerPackSource $source): ?string
    {
        try {
            $repo = $this->api(sprintf('/repos/%s/%s', $source->owner, $source->repo));
        } catch (ServiceUnavailableException) {
            return null;
        }
        $license = is_array($repo['license'] ?? null) ? $repo['license'] : [];
        $spdx = $license['spdx_id'] ?? null;

        return is_string($spdx) && '' !== $spdx && 'NOASSERTION' !== $spdx && strlen($spdx) <= 64 ? $spdx : null;
    }

    /**
     * @return array<mixed>|null the decoded body, or null on 404
     *
     * @throws ServiceUnavailableException
     */
    private function api(string $path): ?array
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'archilan-fr',
        ];
        if ('' !== $this->githubToken) {
            $headers['Authorization'] = 'Bearer '.$this->githubToken;
        }

        try {
            $response = $this->httpClient->request('GET', self::API.$path, [
                'headers' => $headers,
                'max_redirects' => 0,
                'max_duration' => self::MAX_DURATION,
            ]);
            $status = $response->getStatusCode();
            if (404 === $status) {
                return null;
            }
            $this->assertNotRateLimited($response, $path);
            if (200 !== $status) {
                $this->logger->warning('tracker_pack.github_api_failed', ['path' => $path, 'status' => $status]);
                throw self::unavailable();
            }
            $data = json_decode($response->getContent(false), true);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('tracker_pack.github_api_failed', ['path' => $path, 'error' => $e->getMessage()]);
            throw self::unavailable();
        }

        return is_array($data) ? $data : [];
    }

    private function assertNotRateLimited(ResponseInterface $response, string $url): void
    {
        $status = $response->getStatusCode();
        $remaining = $response->getHeaders(false)['x-ratelimit-remaining'][0] ?? null;
        if (429 === $status || (403 === $status && '0' === $remaining)) {
            $this->logger->warning('tracker_pack.github_rate_limited', ['url' => $url, 'tokenConfigured' => '' !== $this->githubToken]);
            throw new ServiceUnavailableException('GitHub limite le nombre de requêtes pour le moment, réessaie plus tard.', 'github_rate_limited');
        }
    }

    /**
     * @throws ValidationException         tracker_pack_too_large, tracker_pack_release_not_found
     * @throws ServiceUnavailableException
     */
    private function download(string $url, int $cap = TrackerPackVersion::MAX_ARCHIVE_BYTES): string
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            if (!self::isAllowed($url)) {
                $this->logger->warning('tracker_pack.download_host_refused', ['url' => $url]);
                throw self::refused('tracker_pack_url_invalid', 'Ce lien n\'est pas un lien GitHub de pack : colle le lien d\'une release, du dépôt ou d\'un zip.');
            }
            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => ['User-Agent' => 'archilan-fr', 'Accept' => 'application/octet-stream'],
                    'max_redirects' => 0,
                    'max_duration' => self::MAX_DURATION,
                ]);
                $status = $response->getStatusCode();
                if ($status >= 300 && $status < 400) {
                    $location = $response->getHeaders(false)['location'][0] ?? null;
                    if (!is_string($location) || '' === $location) {
                        throw self::unavailable();
                    }
                    $url = str_starts_with($location, '/') ? self::origin($url).$location : $location;
                    continue;
                }
                if (404 === $status) {
                    throw self::refused('tracker_pack_release_not_found', 'Le zip de ce lien est introuvable sur GitHub.');
                }
                $this->assertNotRateLimited($response, $url);
                if (200 !== $status) {
                    $this->logger->warning('tracker_pack.download_failed', ['url' => $url, 'status' => $status]);
                    throw self::unavailable();
                }

                return $this->readCapped($response, $cap);
            } catch (ExceptionInterface $e) {
                $this->logger->warning('tracker_pack.download_failed', ['url' => $url, 'error' => $e->getMessage()]);
                throw self::unavailable();
            }
        }

        $this->logger->warning('tracker_pack.download_failed', ['url' => $url, 'error' => 'too many redirects']);
        throw self::unavailable();
    }

    /** @throws ExceptionInterface */
    private function readCapped(ResponseInterface $response, int $cap): string
    {
        $declared = $response->getHeaders(false)['content-length'][0] ?? null;
        if (is_numeric($declared) && (int) $declared > $cap) {
            $response->cancel();
            throw self::refused('tracker_pack_too_large', self::TOO_LARGE);
        }
        $body = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $body .= $chunk->getContent();
            if (strlen($body) > $cap) {
                $response->cancel();
                throw self::refused('tracker_pack_too_large', self::TOO_LARGE);
            }
        }

        return $body;
    }

    private static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);

        return false !== $parts
            && 'https' === ($parts['scheme'] ?? null)
            && !isset($parts['user'])
            && !isset($parts['port'])
            && in_array(strtolower($parts['host'] ?? ''), self::ALLOWED_HOSTS, true);
    }

    private static function origin(string $url): string
    {
        $host = parse_url($url, \PHP_URL_HOST);

        return 'https://'.(is_string($host) ? $host : '');
    }

    private static function refused(string $code, string $message): ValidationException
    {
        return new ValidationException($message, ['url' => [$message]], $code);
    }

    private static function unavailable(): ServiceUnavailableException
    {
        return new ServiceUnavailableException('GitHub ne répond pas pour le moment, réessaie dans un instant.', 'tracker_pack_download_failed');
    }
}
