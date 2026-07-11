<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Entity\Game;
use PHPUnit\Framework\TestCase;

final class GameAvailabilityLockTest extends TestCase
{
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
            new \DateTimeImmutable('2026-07-10T10:00:00+00:00'),
        );
    }

    public function testGameIsUnlockedByDefault(): void
    {
        self::assertFalse($this->makeGame()->isAvailabilityLocked());
    }

    public function testLockAvailabilitySetsFlag(): void
    {
        $game = $this->makeGame();
        $game->lockAvailability();
        self::assertTrue($game->isAvailabilityLocked());
    }

    public function testUnlockAvailabilityClearsFlag(): void
    {
        $game = $this->makeGame();
        $game->lockAvailability();
        $game->unlockAvailability();
        self::assertFalse($game->isAvailabilityLocked());
    }
}
