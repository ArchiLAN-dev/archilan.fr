<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\BackfillGameLocations;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BackfillGameLocationsTest extends TestCase
{
    public function testBackfillsOnlyApworldGamesWithLocations(): void
    {
        $now = new \DateTimeImmutable('2026-07-19T10:00:00+00:00');

        $withApworld = Game::create('Muse Dash', 'muse-dash', '', null, '', '', 'available', $now);
        $withApworld->configureApworld('key.apworld', 'hash-md', 'Muse Dash', 'game: Muse Dash', $now);

        $noApworld = Game::create('Draft Game', 'draft-game', '', null, '', '', 'available', $now);

        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchLocationNames')->willReturnMap([
            ['hash-md', ['Boss Reward', 'Chest 1', 'Chest 2']],
        ]);

        $saved = [];
        $repo = self::createStub(GameRepositoryInterface::class);
        $repo->method('findAllSortedByName')->willReturn([$withApworld, $noApworld]);
        $repo->method('save')->willReturnCallback(function (Game $g) use (&$saved): void {
            $saved[] = $g;
        });

        $result = new BackfillGameLocations($repo, $runner, new NullLogger())->run();

        self::assertSame(1, $result->processed);
        self::assertSame(1, $result->updated);
        self::assertCount(1, $saved);
        self::assertSame(['Boss Reward', 'Chest 1', 'Chest 2'], $withApworld->getLocationNames());
        self::assertNull($noApworld->getLocationNames());
    }

    /** Story 9.58: the backfill records the items too, once the orchestrator knows them. */
    public function testBackfillsTheItemNamesAndGroups(): void
    {
        $now = new \DateTimeImmutable('2026-10-19T10:00:00+00:00');
        $game = Game::create('Hollow Knight', 'hollow-knight', '', null, '', '', 'available', $now);
        $game->configureApworld('key.apworld', 'hash-hk', 'Hollow Knight', 'game: Hollow Knight', $now);

        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchLocationNames')->willReturn(['Crossroads_Stag']);
        $runner->method('fetchItems')->willReturn(['items' => ['Lurien', 'Mantis_Claw'], 'groups' => ['Dreamers' => ['Lurien']]]);

        $repo = self::createStub(GameRepositoryInterface::class);
        $repo->method('findAllSortedByName')->willReturn([$game]);

        $result = new BackfillGameLocations($repo, $runner, new NullLogger())->run();

        self::assertSame(1, $result->updated);
        self::assertSame(['Lurien', 'Mantis_Claw'], $game->getItemNames());
        self::assertSame(['Dreamers' => ['Lurien']], $game->getItemGroups());
    }

    public function testEmptyLocationListLeavesGameNull(): void
    {
        $now = new \DateTimeImmutable('2026-07-19T10:00:00+00:00');

        $game = Game::create('Muse Dash', 'muse-dash', '', null, '', '', 'available', $now);
        $game->configureApworld('key.apworld', 'hash-md', 'Muse Dash', 'game: Muse Dash', $now);

        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchLocationNames')->willReturn([]);

        $repo = self::createStub(GameRepositoryInterface::class);
        $repo->method('findAllSortedByName')->willReturn([$game]);

        $result = new BackfillGameLocations($repo, $runner, new NullLogger())->run();

        self::assertSame(1, $result->processed);
        self::assertSame(0, $result->updated);
        self::assertNull($game->getLocationNames());
    }
}
