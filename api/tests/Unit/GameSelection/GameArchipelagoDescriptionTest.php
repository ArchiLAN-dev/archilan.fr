<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Entity\Game;
use PHPUnit\Framework\TestCase;

/**
 * Optional public Archipelago-side description on a game (story 3.13).
 */
final class GameArchipelagoDescriptionTest extends TestCase
{
    public function testDefaultsToNull(): void
    {
        self::assertNull($this->makeGame()->getArchipelagoDescription());
    }

    public function testRecordTrimsAndStores(): void
    {
        $game = $this->makeGame();
        $game->recordArchipelagoDescription("  Tous les coffres sont randomisés.\n  ");

        self::assertSame('Tous les coffres sont randomisés.', $game->getArchipelagoDescription());
    }

    public function testWhitespaceOnlyBecomesNull(): void
    {
        $game = $this->makeGame();
        $game->recordArchipelagoDescription('   ');

        self::assertNull($game->getArchipelagoDescription());
    }

    public function testNullClearsIt(): void
    {
        $game = $this->makeGame();
        $game->recordArchipelagoDescription('quelque chose');
        $game->recordArchipelagoDescription(null);

        self::assertNull($game->getArchipelagoDescription());
    }

    private function makeGame(): Game
    {
        return Game::create(
            'Test Game',
            'test-game',
            'A description.',
            null,
            'Test Game cover',
            '',
            Game::AVAILABILITY_AVAILABLE,
            new \DateTimeImmutable('2026-07-22T10:00:00+00:00'),
        );
    }
}
