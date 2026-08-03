<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\ValueObject\PlatformCategory;
use PHPUnit\Framework\TestCase;

/**
 * Story 9.47: IGDB describes the game (often every platform it ever shipped on), the admin
 * override describes what the Archipelago world actually supports.
 */
final class PlatformOverrideTest extends TestCase
{
    private const array IGDB = [
        ['id' => 6, 'name' => 'PC (Microsoft Windows)'],
        ['id' => 130, 'name' => 'Nintendo Switch'],
        ['id' => 48, 'name' => 'PlayStation 4'],
    ];

    public function testResolveFallsBackToIgdbWhenThereIsNoOverride(): void
    {
        self::assertSame(['PC', 'PlayStation', 'Switch'], PlatformCategory::resolve(null, self::IGDB));
    }

    public function testResolveTreatsAnEmptyOverrideAsAbsent(): void
    {
        self::assertSame(['PC', 'PlayStation', 'Switch'], PlatformCategory::resolve([], self::IGDB));
    }

    public function testResolvePrefersTheOverride(): void
    {
        self::assertSame(['PC'], PlatformCategory::resolve(['PC'], self::IGDB));
    }

    public function testResolveCleansTheOverride(): void
    {
        self::assertSame(['PC', 'Switch'], PlatformCategory::resolve([' Switch ', 'PC', 'Switch', '  '], self::IGDB));
    }

    public function testSelectableFamiliesAreSortedAndCurated(): void
    {
        $families = PlatformCategory::selectableFamilies();

        self::assertContains('PC', $families);
        self::assertContains('Switch', $families);
        self::assertNotContains('PC (Microsoft Windows)', $families, 'raw IGDB names are not selectable');
        $sorted = $families;
        sort($sorted);
        self::assertSame($sorted, $families);
    }

    public function testGameUsesTheOverrideAndCanRevert(): void
    {
        $now = new \DateTimeImmutable('2026-08-01T16:00:00+00:00');
        $game = Game::create('Timespinner', 'timespinner', 'desc', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, $now);

        self::assertFalse($game->hasPlatformOverride());

        $game->overridePlatformFamilies(['PC'], $now);
        self::assertTrue($game->hasPlatformOverride());
        self::assertSame(['PC'], $game->platformFamilies());

        $game->overridePlatformFamilies(null, $now);
        self::assertFalse($game->hasPlatformOverride());
        // No catalog sync on this game, so the derived list is simply empty - not the stale override.
        self::assertSame([], $game->platformFamilies());
    }
}
