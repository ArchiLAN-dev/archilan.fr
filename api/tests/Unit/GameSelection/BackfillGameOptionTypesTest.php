<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\BackfillGameOptionTypes;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\Sessions\Application\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BackfillGameOptionTypesTest extends TestCase
{
    public function testBackfillsOnlyApworldGamesWithBounds(): void
    {
        $now = new \DateTimeImmutable('2026-06-12T10:00:00+00:00');

        $withApworld = Game::create('Muse Dash', 'muse-dash', '', null, '', '', 'available', $now);
        $withApworld->configureApworld('key.apworld', 'hash-md', 'Muse Dash', 'game: Muse Dash', $now);

        $noApworld = Game::create('Draft Game', 'draft-game', '', null, '', '', 'available', $now);

        $runner = $this->createStub(RunnerGatewayInterface::class);
        $runner->method('fetchOptionTypes')->willReturnMap([
            ['hash-md', ['song_difficulty_min' => ['min' => 1, 'max' => 11, 'default' => 4]]],
        ]);

        $saved = [];
        $repo = $this->createStub(GameRepositoryInterface::class);
        $repo->method('findAllSortedByName')->willReturn([$withApworld, $noApworld]);
        $repo->method('save')->willReturnCallback(function (Game $g) use (&$saved): void {
            $saved[] = $g;
        });

        $result = (new BackfillGameOptionTypes($repo, $runner, new NullLogger()))->run();

        self::assertSame(1, $result['processed']);
        self::assertSame(1, $result['updated']);
        self::assertCount(1, $saved);
        self::assertSame(['song_difficulty_min' => ['min' => 1, 'max' => 11, 'default' => 4]], $withApworld->getOptionTypes());
        self::assertNull($noApworld->getOptionTypes());
    }
}
