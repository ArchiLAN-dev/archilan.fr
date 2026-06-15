<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\BackfillSteamAppIds;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameCatalogSync;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\GameSelection\Infrastructure\IgdbHttpClientInterface;
use App\GameSelection\Infrastructure\IgdbSearchException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class BackfillSteamAppIdsTest extends TestCase
{
    public function testResolvesOnlyGamesWithIgdbIdAndNoSteamAppId(): void
    {
        $withIgdbNoSteam = $this->gameWith(igdbId: 1234, steamAppId: null);
        $alreadyResolved = $this->gameWith(igdbId: 2222, steamAppId: 999);
        $withoutIgdb = $this->gameWith(igdbId: null, steamAppId: null);
        $igdbButNoSteamEntry = $this->gameWith(igdbId: 4444, steamAppId: null);

        $games = $this->createMock(GameRepositoryInterface::class);
        $games->method('findAllSortedByName')->willReturn([
            $withIgdbNoSteam,
            $alreadyResolved,
            $withoutIgdb,
            $igdbButNoSteamEntry,
        ]);
        $games->expects(self::once())->method('save')->with($withIgdbNoSteam);

        $igdb = $this->createStub(IgdbHttpClientInterface::class);
        $igdb->method('fetchSteamAppId')->willReturnCallback(
            static fn (int $id): ?int => match ($id) {
                1234 => 367520,
                4444 => null,
                default => self::fail('Unexpected IGDB lookup for id '.$id),
            },
        );

        $service = new BackfillSteamAppIds($games, $igdb, $this->createStub(LoggerInterface::class));

        $result = $service->run();

        self::assertSame(2, $result['processed']);
        self::assertSame(1, $result['updated']);
        self::assertSame(367520, $withIgdbNoSteam->getSteamAppId());
        self::assertSame(999, $alreadyResolved->getSteamAppId());
    }

    public function testPerGameFailureIsLoggedAndSkippedWithoutAbortingTheRun(): void
    {
        $failing = $this->gameWith(igdbId: 3333, steamAppId: null);
        $succeeding = $this->gameWith(igdbId: 1234, steamAppId: null);

        $games = $this->createMock(GameRepositoryInterface::class);
        $games->method('findAllSortedByName')->willReturn([$failing, $succeeding]);
        $games->expects(self::once())->method('save')->with($succeeding);

        $igdb = $this->createStub(IgdbHttpClientInterface::class);
        $igdb->method('fetchSteamAppId')->willReturnCallback(
            static fn (int $id): ?int => match ($id) {
                3333 => throw new IgdbSearchException('boom'),
                1234 => 367520,
                default => null,
            },
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $service = new BackfillSteamAppIds($games, $igdb, $logger);

        $result = $service->run();

        self::assertSame(2, $result['processed']);
        self::assertSame(1, $result['updated']);
        self::assertNull($failing->getSteamAppId());
        self::assertSame(367520, $succeeding->getSteamAppId());
    }

    private function gameWith(?int $igdbId, ?int $steamAppId): Game
    {
        $now = new \DateTimeImmutable('2026-06-15T10:00:00+00:00');
        $game = Game::create('Hollow Knight', 'hollow-knight', 'Desc.', null, 'Alt', 'Credit', Game::AVAILABILITY_AVAILABLE, $now);

        if (null !== $igdbId || null !== $steamAppId) {
            $sync = new GameCatalogSync($game, igdbId: $igdbId, steamAppId: $steamAppId);
            $game->setCatalogSync($sync);
        }

        return $game;
    }
}
