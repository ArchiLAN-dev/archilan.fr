<?php

declare(strict_types=1);

namespace App\Tests\Unit\CatalogSync;

use App\CatalogSync\Application\Command\BackfillApworldDeployedVersionService;
use App\CatalogSync\Application\Service\ApworldVersionChecker;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BackfillApworldDeployedVersionServiceTest extends TestCase
{
    private function makeGame(string $name, string $slug, string $sourceUrl, string $apworldBytes): Game
    {
        $now = new \DateTimeImmutable();
        $game = Game::create($name, $slug, 'desc', null, $name.' cover', '', Game::AVAILABILITY_AVAILABLE, $now);
        $game->configureApworld('storage/'.$slug, hash('sha256', $apworldBytes), 'ap-'.$slug, 'default-yaml', $now);
        $game->updateCatalogueMetadata(sourceUrl: $sourceUrl);

        return $game;
    }

    /**
     * A single release with one .apworld asset; its bytes hash to the "matching" game's hash.
     *
     * @return list<MockResponse>
     */
    private function singleReleaseResponses(string $tag, string $assetBytes, int $rateLimitRemaining = 50): array
    {
        return [
            new MockResponse(
                (string) json_encode([[
                    'tag_name' => $tag,
                    'name' => 'Release',
                    'draft' => false,
                    'assets' => [
                        ['name' => 'world.apworld', 'browser_download_url' => 'https://example.com/world.apworld'],
                    ],
                ]]),
                ['response_headers' => ['x-ratelimit-remaining' => [(string) $rateLimitRemaining]]],
            ),
            new MockResponse($assetBytes),
        ];
    }

    public function testMatchingGameGetsDeployedVersionUnmatchedStaysNull(): void
    {
        $matching = $this->makeGame('Matching', 'matching', 'https://github.com/acme/worlds', 'THE-BYTES');
        $unmatching = $this->makeGame('Unmatching', 'unmatching', 'https://github.com/acme/worlds', 'OTHER-BYTES');

        $checker = new ApworldVersionChecker(
            new MockHttpClient($this->singleReleaseResponses('v3.1.0', 'THE-BYTES')),
            new NullLogger(),
            'ghp_test_token',
        );

        $repository = $this->createMock(GameRepositoryInterface::class);
        $repository->method('findAllSortedByName')->willReturn([$matching, $unmatching]);
        $repository->expects(self::once())->method('flush');

        $service = new BackfillApworldDeployedVersionService($checker, $repository, new NullLogger());
        $result = $service->backfill(false);

        self::assertSame('3.1.0', $matching->getApworldDeployedVersion());
        self::assertNull($unmatching->getApworldDeployedVersion());
        self::assertSame(1, $result->matched);
        self::assertSame(1, $result->unmatched);
        self::assertSame(2, $result->total);
        self::assertSame(['Unmatching'], $result->unmatchedGames);
        self::assertFalse($result->rateLimitHit);
    }

    public function testDryRunPersistsNothing(): void
    {
        $matching = $this->makeGame('Matching', 'matching', 'https://github.com/acme/worlds', 'THE-BYTES');

        $checker = new ApworldVersionChecker(
            new MockHttpClient($this->singleReleaseResponses('v3.1.0', 'THE-BYTES')),
            new NullLogger(),
            'ghp_test_token',
        );

        $repository = $this->createMock(GameRepositoryInterface::class);
        $repository->method('findAllSortedByName')->willReturn([$matching]);
        $repository->expects(self::never())->method('flush');

        $service = new BackfillApworldDeployedVersionService($checker, $repository, new NullLogger());
        $result = $service->backfill(true);

        self::assertNull($matching->getApworldDeployedVersion());
        self::assertSame(1, $result->matched);
    }

    public function testGamesWithoutHashOrAlreadyDeployedAreSkipped(): void
    {
        $now = new \DateTimeImmutable();

        // No apworld hash -> not a target.
        $noHash = Game::create('NoHash', 'nohash', 'desc', null, 'cover', '', Game::AVAILABILITY_AVAILABLE, $now);
        $noHash->updateCatalogueMetadata(sourceUrl: 'https://github.com/acme/worlds');

        // Already has a deployed version -> not a target.
        $alreadyDeployed = $this->makeGame('Deployed', 'deployed', 'https://github.com/acme/worlds', 'X');
        $alreadyDeployed->getCatalogSync()?->recordApworldDeployment('9.9.9');

        // Non-GitHub source -> not a target.
        $direct = $this->makeGame('Direct', 'direct', 'https://example.com/worlds/foo.apworld', 'Y');

        $checker = new ApworldVersionChecker(new MockHttpClient(), new NullLogger(), 'ghp_test_token');

        $repository = self::createStub(GameRepositoryInterface::class);
        $repository->method('findAllSortedByName')->willReturn([$noHash, $alreadyDeployed, $direct]);

        $service = new BackfillApworldDeployedVersionService($checker, $repository, new NullLogger());
        $result = $service->backfill(false);

        self::assertSame(0, $result->total);
        self::assertSame('9.9.9', $alreadyDeployed->getApworldDeployedVersion());
    }

    public function testRateLimitStopsEarlyAndFlagsHit(): void
    {
        $game = $this->makeGame('Matching', 'matching', 'https://github.com/acme/worlds', 'THE-BYTES');

        // remaining <= 10 makes the checker throw after building the page - the service stops.
        $checker = new ApworldVersionChecker(
            new MockHttpClient($this->singleReleaseResponses('v3.1.0', 'THE-BYTES', 5)),
            new NullLogger(),
            'ghp_test_token',
        );

        $repository = self::createStub(GameRepositoryInterface::class);
        $repository->method('findAllSortedByName')->willReturn([$game]);

        $service = new BackfillApworldDeployedVersionService($checker, $repository, new NullLogger());
        $result = $service->backfill(false);

        self::assertTrue($result->rateLimitHit);
        self::assertNull($game->getApworldDeployedVersion());
        self::assertSame(0, $result->matched);
    }
}
