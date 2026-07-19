<?php

declare(strict_types=1);

namespace App\Tests\Unit\CatalogSync;

use App\CatalogSync\Application\Exception\GithubRateLimitException;
use App\CatalogSync\Application\Service\ApworldVersionChecker;
use App\CatalogSync\Application\Support\ApworldVersionInfo;
use App\GameSelection\Domain\Entity\Game;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ApworldVersionCheckerTest extends TestCase
{
    private function makeGame(?string $sourceUrl, ?string $deployedVersion = null): Game
    {
        $game = Game::create(
            'Hollow Knight',
            'hollow-knight',
            'A platformer.',
            null,
            'Hollow Knight cover',
            '',
            Game::AVAILABILITY_AVAILABLE,
            new \DateTimeImmutable(),
        );
        $game->updateCatalogueMetadata(sourceUrl: $sourceUrl, deployedVersion: $deployedVersion);

        return $game;
    }

    /**
     * @param array<mixed> $assets
     *
     * @return list<MockResponse>
     */
    private function releaseResponse(string $tag, string $publishedAt, array $assets = [], int $rateLimitRemaining = 50): array
    {
        return [
            new MockResponse(
                (string) json_encode([[
                    'tag_name' => $tag,
                    'published_at' => $publishedAt,
                    'html_url' => 'https://github.com/nicholasb/hollow-knight/releases/tag/'.$tag,
                    'assets' => $assets,
                    'draft' => false,
                    'prerelease' => false,
                ]]),
                ['response_headers' => ['x-ratelimit-remaining' => [(string) $rateLimitRemaining]]],
            ),
        ];
    }

    public function testCheckReturnsVersionInfoWhenTagHasVPrefix(): void
    {
        $game = $this->makeGame('https://github.com/nicholasb/hollow-knight');
        $mock = new MockHttpClient($this->releaseResponse('v1.2.0', '2026-01-01T00:00:00Z', [
            ['name' => 'hollow-knight.apworld', 'browser_download_url' => 'https://example.com/hk.apworld'],
            ['name' => 'source.tar.gz', 'browser_download_url' => 'https://example.com/src.tar.gz'],
        ]));

        $checker = new ApworldVersionChecker($mock, new NullLogger(), 'ghp_test_token');
        $info = $checker->check($game);

        self::assertInstanceOf(ApworldVersionInfo::class, $info);
        self::assertSame('1.2.0', $info->latestTag);
        self::assertSame('hollow-knight.apworld', $info->assetName);
        self::assertSame(Game::UPDATE_STATUS_UNKNOWN, $info->updateStatus);
        self::assertFalse($info->isNewer);
        self::assertSame('1.2.0', $game->getApworldLatestVersion());
    }

    public function testCheckReturnsVersionInfoWhenTagHasNoPrefix(): void
    {
        $game = $this->makeGame('https://github.com/nicholasb/hollow-knight', '1.2.0');
        $mock = new MockHttpClient($this->releaseResponse('1.2.0', '2026-01-01T00:00:00Z', [
            ['name' => 'hollow-knight.apworld', 'browser_download_url' => 'https://example.com/hk.apworld'],
        ]));

        $checker = new ApworldVersionChecker($mock, new NullLogger(), 'ghp_test_token');
        $info = $checker->check($game);

        self::assertInstanceOf(ApworldVersionInfo::class, $info);
        self::assertSame('1.2.0', $info->latestTag);
        self::assertSame('hollow-knight.apworld', $info->assetName);
        self::assertSame(Game::UPDATE_STATUS_UP_TO_DATE, $info->updateStatus);
        self::assertFalse($info->isNewer);
    }

    public function testCheckReturnsNullWhenNoRelease(): void
    {
        $game = $this->makeGame('https://github.com/nicholasb/hollow-knight');
        $mock = new MockHttpClient([
            new MockResponse('{"message":"Not Found"}', ['http_code' => 404]),
        ]);

        $checker = new ApworldVersionChecker($mock, new NullLogger(), 'ghp_test_token');

        self::assertNull($checker->check($game));
    }

    public function testCheckReturnsNullWhenNonGithubUrl(): void
    {
        $game = $this->makeGame('https://example.com/some-repo');
        $mock = new MockHttpClient();

        $checker = new ApworldVersionChecker($mock, new NullLogger(), 'ghp_test_token');

        self::assertNull($checker->check($game));
        self::assertSame(0, $mock->getRequestsCount());
    }

    public function testCheckReturnsNullAndLogsWarningWhenTokenMissing(): void
    {
        $game = $this->makeGame('https://github.com/nicholasb/hollow-knight');
        $mock = new MockHttpClient();

        $logger = new ApworldSpyLogger();
        $checker = new ApworldVersionChecker($mock, $logger, '');

        self::assertNull($checker->check($game));
        self::assertCount(1, $logger->warnings);
        self::assertSame(0, $mock->getRequestsCount());
    }

    public function testCheckThrowsGithubRateLimitExceptionWhenRateLimitLow(): void
    {
        $game = $this->makeGame('https://github.com/nicholasb/hollow-knight');
        $mock = new MockHttpClient($this->releaseResponse('v1.0.0', '2026-01-01T00:00:00Z', [
            ['name' => 'hollow-knight.apworld', 'browser_download_url' => 'https://example.com/hk.apworld'],
        ], 5));

        $checker = new ApworldVersionChecker($mock, new NullLogger(), 'ghp_test_token');

        try {
            $checker->check($game);
            self::fail('Expected GithubRateLimitException');
        } catch (GithubRateLimitException) {
            self::assertSame('1.0.0', $game->getApworldLatestVersion());
        }
    }

    public function testListAssetsCarriesNormalizedReleaseTag(): void
    {
        $game = $this->makeGame('https://github.com/nicholasb/hollow-knight');
        $mock = new MockHttpClient($this->releaseResponse('v2.3.4', '2026-01-01T00:00:00Z', [
            ['name' => 'hollow-knight.apworld', 'browser_download_url' => 'https://example.com/hk.apworld', 'size' => 1024],
            ['name' => 'source.tar.gz', 'browser_download_url' => 'https://example.com/src.tar.gz', 'size' => 2048],
        ]));

        $checker = new ApworldVersionChecker($mock, new NullLogger(), 'ghp_test_token');
        $assets = $checker->listAssets($game);

        self::assertNotNull($assets);
        self::assertCount(1, $assets);
        self::assertSame('hollow-knight.apworld', $assets[0]['name']);
        self::assertSame('2.3.4', $assets[0]['tag']);
    }

    public function testListAssetsForDirectUrlHasNullTag(): void
    {
        $game = $this->makeGame('https://example.com/worlds/foo.apworld');

        $checker = new ApworldVersionChecker(new MockHttpClient(), new NullLogger(), 'ghp_test_token');
        $assets = $checker->listAssets($game);

        self::assertNotNull($assets);
        self::assertCount(1, $assets);
        self::assertNull($assets[0]['tag']);
    }

    public function testMapApworldAssetHashesByTagCoversEveryRelease(): void
    {
        $game = $this->makeGame('https://github.com/nicholasb/hollow-knight');

        $newBytes = 'HK-APWORLD-BYTES-v2';
        $oldBytes = 'HK-APWORLD-BYTES-v1';

        $releasesPage = new MockResponse(
            (string) json_encode([
                [
                    'tag_name' => 'v2.0.0',
                    'name' => 'Release 2',
                    'draft' => false,
                    'assets' => [
                        ['name' => 'hollow-knight.apworld', 'browser_download_url' => 'https://example.com/hk-2.apworld'],
                        ['name' => 'source.tar.gz', 'browser_download_url' => 'https://example.com/src.tar.gz'],
                    ],
                ],
                [
                    'tag_name' => '1.0.0',
                    'name' => 'Release 1',
                    'draft' => false,
                    'assets' => [
                        ['name' => 'hollow-knight.apworld', 'browser_download_url' => 'https://example.com/hk-1.apworld'],
                    ],
                ],
            ]),
            ['response_headers' => ['x-ratelimit-remaining' => ['50']]],
        );

        $mock = new MockHttpClient([
            $releasesPage,
            new MockResponse($newBytes),
            new MockResponse($oldBytes),
        ]);

        $checker = new ApworldVersionChecker($mock, new NullLogger(), 'ghp_test_token');
        $map = $checker->mapApworldAssetHashesByTag($game);

        // The OLD release is mapped too: the scan does not stop at the latest, which is the
        // whole point of matching a possibly-outdated deployed apworld by content.
        self::assertCount(2, $map);
        self::assertSame('2.0.0', $map[hash('sha256', $newBytes)]);
        self::assertSame('1.0.0', $map[hash('sha256', $oldBytes)]);
    }

    public function testMapApworldAssetHashesByTagIsEmptyForDirectUrl(): void
    {
        $game = $this->makeGame('https://example.com/worlds/foo.apworld');

        $checker = new ApworldVersionChecker(new MockHttpClient(), new NullLogger(), 'ghp_test_token');

        self::assertSame([], $checker->mapApworldAssetHashesByTag($game));
    }
}

final class ApworldSpyLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        if ('warning' === $level) {
            $this->warnings[] = (string) $message;
        }
    }
}
