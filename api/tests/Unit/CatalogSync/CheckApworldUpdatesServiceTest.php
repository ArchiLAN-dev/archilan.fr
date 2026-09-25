<?php

declare(strict_types=1);

namespace App\Tests\Unit\CatalogSync;

use App\CatalogSync\Application\Command\ApworldUpdateAvailable;
use App\CatalogSync\Application\Command\CheckApworldUpdatesService;
use App\CatalogSync\Application\Service\ApworldVersionChecker;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CheckApworldUpdatesServiceTest extends TestCase
{
    /** @var list<string> repos requested, in order */
    private array $requestedRepos = [];
    private int $flushes = 0;

    public function testChecksLeastRecentlyCheckedFirst(): void
    {
        // Alphabetical order was the old one: a rate limit then always starved the end of the alphabet.
        $games = [
            $this->game('Aaa', 'aaa', '1.0.0', new \DateTimeImmutable('2026-09-20')),
            $this->game('Bbb', 'bbb', '1.0.0', null),
            $this->game('Ccc', 'ccc', '1.0.0', new \DateTimeImmutable('2026-09-01')),
        ];

        $this->service($games, fn (string $repo): MockResponse => $this->release('v1.0.0', $repo))->checkAll();

        self::assertSame(['bbb', 'ccc', 'aaa'], $this->requestedRepos, 'never checked first, then the oldest check');
    }

    public function testReportListsGamesWithAnUpdateAvailable(): void
    {
        $games = [
            $this->game('Crystal Project', 'crystal', 'CrystalProject-v0.17.0', null),
            $this->game('Up To Date', 'uptodate', '2.0.0', null),
            $this->game('Older Latest', 'older', '3.0.0', null),
        ];
        $tags = ['crystal' => 'CrystalProject-v0.18.2', 'uptodate' => 'v2.0.0', 'older' => 'v2.9.0'];

        $report = $this->service($games, fn (string $repo): MockResponse => $this->release($tags[$repo], $repo))->checkAll();

        self::assertSame(3, $report->checked);
        self::assertFalse($report->rateLimitHit);
        self::assertCount(1, $report->updatesAvailable);
        $update = $report->updatesAvailable[0];
        self::assertInstanceOf(ApworldUpdateAvailable::class, $update);
        self::assertSame($games[0]->getId(), $update->gameId);
        self::assertSame('Crystal Project', $update->gameName);
        self::assertSame('CrystalProject-v0.18.2', $update->latestTag);
        self::assertSame('https://github.com/owner/crystal/releases/download/CrystalProject-v0.18.2/world.apworld', $update->assetDownloadUrl);
    }

    public function testANetworkErrorOnOneGameDoesNotStopTheOthers(): void
    {
        // Found by the first real run: one reset connection on one repository aborted the whole pass,
        // and the flush at the end never happened, so every game checked before was lost too.
        $games = [
            $this->game('Aaa', 'aaa', '1.0.0', null),
            $this->game('Bbb', 'bbb', '1.0.0', null),
            $this->game('Ccc', 'ccc', '1.0.0', null),
        ];

        $report = $this->service($games, fn (string $repo): MockResponse => 'bbb' === $repo
            ? new MockResponse('', ['error' => 'Recv failure: Connection was reset'])
            : $this->release('v1.1.0', $repo))->checkAll();

        self::assertSame(['aaa', 'bbb', 'ccc'], $this->requestedRepos);
        self::assertSame(2, $report->checked);
        self::assertSame(1, $report->failed);
        self::assertCount(2, $report->updatesAvailable);
        self::assertSame(1, $this->flushes, 'what was checked is saved');
    }

    public function testStopsOnRateLimitAndReportsIt(): void
    {
        $games = [
            $this->game('Aaa', 'aaa', '1.0.0', null),
            $this->game('Bbb', 'bbb', '1.0.0', null),
        ];

        $report = $this->service($games, fn (string $repo): MockResponse => $this->release('v1.1.0', $repo, remaining: 3))->checkAll();

        self::assertTrue($report->rateLimitHit);
        self::assertSame(['aaa'], $this->requestedRepos, 'nothing is requested after the limit');
    }

    /**
     * @param list<Game>                     $games
     * @param callable(string): MockResponse $respond
     */
    private function service(array $games, callable $respond): CheckApworldUpdatesService
    {
        $repository = self::createStub(GameRepositoryInterface::class);
        $repository->method('findAllSortedByName')->willReturn($games);
        $repository->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        $client = new MockHttpClient(function (string $method, string $url) use ($respond): MockResponse {
            $repo = (string) preg_replace('#^https://api\.github\.com/repos/owner/([^/]+)/.*$#', '$1', $url);
            $this->requestedRepos[] = $repo;

            return $respond($repo);
        });

        return new CheckApworldUpdatesService(new ApworldVersionChecker($client, new NullLogger(), 'ghp_test_token'), $repository, new NullLogger());
    }

    private function game(string $name, string $repo, string $deployedVersion, ?\DateTimeImmutable $checkedAt): Game
    {
        $game = Game::create($name, strtolower($name), 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        $game->updateCatalogueMetadata(sourceUrl: 'https://github.com/owner/'.$repo, deployedVersion: $deployedVersion);
        if (null !== $checkedAt) {
            $game->recordApworldCheck('0.0.1', $checkedAt);
        }

        return $game;
    }

    private function release(string $tag, string $repo, int $remaining = 4000): MockResponse
    {
        return new MockResponse((string) json_encode([[
            'tag_name' => $tag,
            'name' => $tag,
            'published_at' => '2026-09-11T22:15:28Z',
            'html_url' => 'https://github.com/owner/'.$repo.'/releases/tag/'.$tag,
            'draft' => false,
            'prerelease' => false,
            'assets' => [[
                'name' => 'world.apworld',
                'browser_download_url' => 'https://github.com/owner/'.$repo.'/releases/download/'.$tag.'/world.apworld',
                'size' => 1024,
            ]],
        ]]), ['response_headers' => ['x-ratelimit-remaining' => [(string) $remaining]]]);
    }
}
