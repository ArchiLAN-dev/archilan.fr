<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\BackfillGamePlatforms;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameCatalogSync;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\GameSelection\Infrastructure\IgdbHttpClientInterface;
use App\GameSelection\Infrastructure\IgdbSearchException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class BackfillGamePlatformsTest extends TestCase
{
    public function testResolvesOnlyGamesWithIgdbIdAndNoPlatforms(): void
    {
        $toResolve = $this->gameWith(igdbId: 1234, platforms: null);
        $alreadyResolved = $this->gameWith(igdbId: 2222, platforms: [['id' => 6, 'name' => 'PC (Microsoft Windows)']]);
        $withoutIgdb = $this->gameWith(igdbId: null, platforms: null);

        $games = $this->createMock(GameRepositoryInterface::class);
        $games->method('findAllSortedByName')->willReturn([$toResolve, $alreadyResolved, $withoutIgdb]);
        $games->expects(self::once())->method('save')->with($toResolve);

        $igdb = $this->createStub(IgdbHttpClientInterface::class);
        $igdb->method('fetchPlatforms')->willReturnCallback(
            static fn (int $id): array => 1234 === $id ? [['id' => 19, 'name' => 'Super Nintendo Entertainment System']] : self::fail('unexpected '.$id),
        );

        $service = new BackfillGamePlatforms($games, $igdb, $this->createStub(LoggerInterface::class));

        $result = $service->run();

        self::assertSame(1, $result['processed']);
        self::assertSame(1, $result['updated']);
        self::assertSame([['id' => 19, 'name' => 'Super Nintendo Entertainment System']], $toResolve->getPlatforms());
    }

    public function testPerGameFailureIsLoggedAndSkipped(): void
    {
        $failing = $this->gameWith(igdbId: 3333, platforms: null);

        $games = $this->createMock(GameRepositoryInterface::class);
        $games->method('findAllSortedByName')->willReturn([$failing]);
        $games->expects(self::never())->method('save');

        $igdb = $this->createStub(IgdbHttpClientInterface::class);
        $igdb->method('fetchPlatforms')->willThrowException(new IgdbSearchException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $service = new BackfillGamePlatforms($games, $igdb, $logger);

        $result = $service->run();

        self::assertSame(1, $result['processed']);
        self::assertSame(0, $result['updated']);
        self::assertNull($failing->getPlatforms());
    }

    /**
     * @param list<array{id: int, name: string}>|null $platforms
     */
    private function gameWith(?int $igdbId, ?array $platforms): Game
    {
        $now = new \DateTimeImmutable('2026-06-15T10:00:00+00:00');
        $game = Game::create('Game', 'game-'.bin2hex(random_bytes(4)), 'Desc.', null, 'Alt', 'Credit', Game::AVAILABILITY_AVAILABLE, $now);

        if (null !== $igdbId || null !== $platforms) {
            $sync = new GameCatalogSync($game, igdbId: $igdbId, platforms: $platforms);
            $game->setCatalogSync($sync);
        }

        return $game;
    }
}
