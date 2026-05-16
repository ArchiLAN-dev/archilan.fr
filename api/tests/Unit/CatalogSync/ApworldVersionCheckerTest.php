<?php

declare(strict_types=1);

namespace App\Tests\Unit\CatalogSync;

use App\CatalogSync\Application\ApworldVersionChecker;
use App\CatalogSync\Application\ApworldVersionInfo;
use App\CatalogSync\Application\GithubRateLimitException;
use App\GameSelection\Domain\Game;
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
                (string) json_encode([
                    'tag_name' => $tag,
                    'published_at' => $publishedAt,
                    'html_url' => 'https://github.com/nicholasb/hollow-knight/releases/tag/'.$tag,
                    'assets' => $assets,
                ]),
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
        $mock = new MockHttpClient($this->releaseResponse('1.2.0', '2026-01-01T00:00:00Z'));

        $checker = new ApworldVersionChecker($mock, new NullLogger(), 'ghp_test_token');
        $info = $checker->check($game);

        self::assertInstanceOf(ApworldVersionInfo::class, $info);
        self::assertSame('1.2.0', $info->latestTag);
        self::assertNull($info->assetName);
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
        $mock = new MockHttpClient($this->releaseResponse('v1.0.0', '2026-01-01T00:00:00Z', [], 5));

        $checker = new ApworldVersionChecker($mock, new NullLogger(), 'ghp_test_token');

        try {
            $checker->check($game);
            self::fail('Expected GithubRateLimitException');
        } catch (GithubRateLimitException) {
            self::assertSame('1.0.0', $game->getApworldLatestVersion());
        }
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
