<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Game;
use PHPUnit\Framework\TestCase;

final class GameConfigureApworldBomTest extends TestCase
{
    public function testConfigureApworldStripsLeadingBom(): void
    {
        $game = $this->game();
        $withBom = "\u{FEFF}name: Player{number}\ngame: Paint\n";

        $game->configureApworld('storage/key', 'hash', 'Paint', $withBom, new \DateTimeImmutable());

        self::assertSame("name: Player{number}\ngame: Paint\n", $game->getDefaultYaml());
        self::assertStringStartsNotWith("\u{FEFF}", (string) $game->getDefaultYaml());
    }

    public function testConfigureApworldLeavesBomlessYamlUnchanged(): void
    {
        $game = $this->game();
        $clean = "name: Player{number}\ngame: Paint\n";

        $game->configureApworld('storage/key', 'hash', 'Paint', $clean, new \DateTimeImmutable());

        self::assertSame($clean, $game->getDefaultYaml());
    }

    private function game(): Game
    {
        return Game::create('Paint', 'paint', 'A game.', null, 'alt', 'credit', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
    }
}
