<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Entity\Game;
use PHPUnit\Framework\TestCase;

/**
 * Admin-only free-text notes on a game (story 3.12). Whitespace-only input clears the note.
 */
final class GameAdminNotesTest extends TestCase
{
    public function testDefaultsToNull(): void
    {
        self::assertNull($this->makeGame()->getAdminNotes());
    }

    public function testRecordTrimsAndStores(): void
    {
        $game = $this->makeGame();
        $game->recordAdminNotes("  Attention: apworld 0.6 casse le YAML.\n  ");

        self::assertSame('Attention: apworld 0.6 casse le YAML.', $game->getAdminNotes());
    }

    public function testWhitespaceOnlyBecomesNull(): void
    {
        $game = $this->makeGame();
        $game->recordAdminNotes('   ');

        self::assertNull($game->getAdminNotes());
    }

    public function testNullClearsTheNote(): void
    {
        $game = $this->makeGame();
        $game->recordAdminNotes('something');
        $game->recordAdminNotes(null);

        self::assertNull($game->getAdminNotes());
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
            new \DateTimeImmutable('2026-07-19T10:00:00+00:00'),
        );
    }
}
