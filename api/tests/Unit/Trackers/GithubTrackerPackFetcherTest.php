<?php

declare(strict_types=1);

namespace App\Tests\Unit\Trackers;

use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Exception\ValidationException;
use App\Trackers\Domain\ValueObject\TrackerPackSource;
use App\Trackers\Infrastructure\Http\GithubTrackerPackFetcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GithubTrackerPackFetcherTest extends TestCase
{
    /** @var list<array{method: string, url: string, auth: bool}> */
    private array $requests = [];

    public function testReleaseWithoutAssetDownloadsTheTagArchive(): void
    {
        $fetcher = $this->fetcher([
            'https://api.github.com/repos/o/r/releases/tags/v1.0' => self::json(['tag_name' => 'v1.0', 'assets' => []]),
            'https://github.com/o/r/archive/refs/tags/v1.0.zip' => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://codeload.github.com/o/r/zip/refs/tags/v1.0']]),
            'https://codeload.github.com/o/r/zip/refs/tags/v1.0' => new MockResponse('ZIPBYTES'),
            'https://api.github.com/repos/o/r' => self::json(['license' => ['spdx_id' => 'MIT']]),
        ], 'token');

        $fetched = $fetcher->fetch(TrackerPackSource::parse('https://github.com/o/r/releases/tag/v1.0'));

        self::assertSame('ZIPBYTES', $fetched->archive);
        self::assertSame('https://github.com/o/r/archive/refs/tags/v1.0.zip', $fetched->downloadUrl);
        self::assertSame('v1.0', $fetched->gitTag);
        self::assertSame('MIT', $fetched->licenseSpdx);
        // The token goes to the API only, never to the download hosts.
        foreach ($this->requests as $request) {
            self::assertSame(str_starts_with($request['url'], 'https://api.github.com/'), $request['auth'], $request['url']);
        }
    }

    public function testLatestReleasePrefersThePackAsset(): void
    {
        $fetcher = $this->fetcher([
            'https://api.github.com/repos/o/r/releases/latest' => self::json(['tag_name' => 'v2', 'assets' => [
                ['name' => 'notes.txt', 'browser_download_url' => 'https://github.com/o/r/releases/download/v2/notes.txt'],
                ['name' => 'extras.zip', 'browser_download_url' => 'https://github.com/o/r/releases/download/v2/extras.zip'],
                ['name' => 'MyGame_PopTracker.zip', 'browser_download_url' => 'https://github.com/o/r/releases/download/v2/MyGame_PopTracker.zip'],
            ]]),
            'https://github.com/o/r/releases/download/v2/MyGame_PopTracker.zip' => new MockResponse('PACK'),
            'https://api.github.com/repos/o/r' => self::json(['license' => ['spdx_id' => 'NOASSERTION']]),
        ]);

        $fetched = $fetcher->fetch(TrackerPackSource::parse('https://github.com/o/r'));

        self::assertSame('PACK', $fetched->archive);
        self::assertSame('v2', $fetched->gitTag);
        self::assertNull($fetched->licenseSpdx);
    }

    public function testUnknownReleaseIsRefused(): void
    {
        $fetcher = $this->fetcher(['https://api.github.com/repos/o/r/releases/latest' => new MockResponse('{}', ['http_code' => 404])]);

        $this->expectFailure('tracker_pack_release_not_found', fn () => $fetcher->fetch(TrackerPackSource::parse('https://github.com/o/r')));
    }

    public function testRedirectOutsideGithubIsRefused(): void
    {
        $fetcher = $this->fetcher([
            'https://github.com/o/r/archive/refs/tags/v1.zip' => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://evil.test/pack.zip']]),
        ]);

        $this->expectFailure('tracker_pack_url_invalid', fn () => $fetcher->fetch(TrackerPackSource::parse('https://github.com/o/r/archive/refs/tags/v1.zip')));
        self::assertNotContains('https://evil.test/pack.zip', array_column($this->requests, 'url'));
    }

    public function testDownloadStopsPastTheSizeCap(): void
    {
        $chunks = (static function (): \Generator {
            for ($i = 0; $i < 51; ++$i) {
                yield str_repeat('z', 1024 * 1024);
            }
        })();
        $fetcher = $this->fetcher([
            'https://github.com/o/r/archive/refs/tags/v1.zip' => new MockResponse($chunks),
        ]);

        $this->expectFailure('tracker_pack_too_large', fn () => $fetcher->fetch(TrackerPackSource::parse('https://github.com/o/r/archive/refs/tags/v1.zip')));
    }

    public function testRateLimitIsUnavailable(): void
    {
        $fetcher = $this->fetcher([
            'https://api.github.com/repos/o/r/releases/latest' => new MockResponse('{}', ['http_code' => 403, 'response_headers' => ['x-ratelimit-remaining' => '0']]),
        ]);

        try {
            $fetcher->fetch(TrackerPackSource::parse('https://github.com/o/r'));
            self::fail('A rate limit should make the import unavailable.');
        } catch (ServiceUnavailableException $e) {
            self::assertSame('github_rate_limited', $e->errorCode());
        }
    }

    public function testServerErrorIsUnavailable(): void
    {
        $fetcher = $this->fetcher([
            'https://github.com/o/r/archive/refs/tags/v1.zip' => new MockResponse('', ['http_code' => 502]),
        ]);

        try {
            $fetcher->fetch(TrackerPackSource::parse('https://github.com/o/r/archive/refs/tags/v1.zip'));
            self::fail('A GitHub error should make the import unavailable.');
        } catch (ServiceUnavailableException $e) {
            self::assertSame('tracker_pack_download_failed', $e->errorCode());
        }
    }

    public function testLatestReleaseIsAnUpdateCandidate(): void
    {
        $fetcher = $this->fetcher([
            'https://api.github.com/repos/o/r/releases/latest' => self::json(['tag_name' => 'v0.9.5.4', 'assets' => [], 'body' => "## Changes\r\n\r\n- Fixed the Great Bay logic"]),
        ]);

        $update = $fetcher->fromLatestRelease(TrackerPackSource::parse('https://github.com/o/r'));

        self::assertNotNull($update);
        self::assertSame('0.9.5.4', $update->packageVersion);
        self::assertSame('v0.9.5.4', $update->gitTag);
        self::assertSame('https://github.com/o/r/archive/refs/tags/v0.9.5.4.zip', $update->downloadUrl);
        self::assertNull($update->sha256);
        self::assertSame(['## Changes', '- Fixed the Great Bay logic'], $update->changelog);
    }

    public function testRepositoryWithoutReleaseHasNoCandidate(): void
    {
        $fetcher = $this->fetcher(['https://api.github.com/repos/o/r/releases/latest' => new MockResponse('{}', ['http_code' => 404])]);

        self::assertNull($fetcher->fromLatestRelease(TrackerPackSource::parse('https://github.com/o/r')));
    }

    public function testVersionsFileIsReadTolerantly(): void
    {
        $fetcher = $this->fetcher([
            'https://raw.githubusercontent.com/o/r/refs/heads/main/versions.json' => new MockResponse("{\"versions\": [{\"package_version\": \"1.2\", \"download_url\": \"https://github.com/o/r/releases/download/v1.2/pack.zip\",},], // last\n}"),
        ]);

        $update = $fetcher->fromVersionsFile('https://raw.githubusercontent.com/o/r/refs/heads/main/versions.json');

        self::assertSame('1.2', $update?->packageVersion);
    }

    public function testVersionsFileOutsideGithubOrUnreadableIsRefused(): void
    {
        $fetcher = $this->fetcher(['https://raw.githubusercontent.com/o/r/main/versions.json' => new MockResponse('not json')]);

        $this->expectFailure('tracker_pack_url_invalid', static fn (): mixed => $fetcher->fromVersionsFile('https://evil.test/versions.json'));
        $this->expectFailure('tracker_pack_versions_file_invalid', static fn (): mixed => $fetcher->fromVersionsFile('https://raw.githubusercontent.com/o/r/main/versions.json'));
    }

    /** @param array<string, MockResponse> $responses by URL; an unexpected URL answers 500 */
    private function fetcher(array $responses, string $token = ''): GithubTrackerPackFetcher
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($responses): MockResponse {
            $headers = $options['normalized_headers'] ?? [];
            $this->requests[] = ['method' => $method, 'url' => $url, 'auth' => is_array($headers) && isset($headers['authorization'])];

            return $responses[$url] ?? new MockResponse('unexpected '.$url, ['http_code' => 500]);
        });

        return new GithubTrackerPackFetcher($client, new NullLogger(), $token);
    }

    /** @param array<mixed> $data */
    private static function json(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
    }

    private function expectFailure(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('The fetch should have been refused.');
        } catch (ValidationException $e) {
            self::assertSame($code, $e->errorCode());
        }
    }
}
