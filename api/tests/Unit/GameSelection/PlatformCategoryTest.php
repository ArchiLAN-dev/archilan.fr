<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\PlatformCategory;
use PHPUnit\Framework\TestCase;

final class PlatformCategoryTest extends TestCase
{
    public function testCollapsesNintendoVariantsAndDedupes(): void
    {
        // Real IGDB shape for "Super Metroid".
        $platforms = [
            ['id' => 19, 'name' => 'Super Nintendo Entertainment System'],
            ['id' => 5, 'name' => 'Wii'],
            ['id' => 41, 'name' => 'Wii U'],
            ['id' => 137, 'name' => 'New Nintendo 3DS'],
            ['id' => 58, 'name' => 'Super Famicom'],
        ];

        self::assertSame(
            ['Nintendo 3DS', 'Super Nintendo', 'Wii', 'Wii U'],
            PlatformCategory::families($platforms),
        );
    }

    public function testMapsNintendo64Variants(): void
    {
        $platforms = [
            ['id' => 5, 'name' => 'Wii'],
            ['id' => 4, 'name' => 'Nintendo 64'],
            ['id' => 416, 'name' => 'Nintendo 64DD'],
            ['id' => 41, 'name' => 'Wii U'],
        ];

        self::assertSame(['Nintendo 64', 'Wii', 'Wii U'], PlatformCategory::families($platforms));
    }

    public function testCollapsesMultiplatformStorefronts(): void
    {
        $platforms = [
            ['id' => 6, 'name' => 'PC (Microsoft Windows)'],
            ['id' => 14, 'name' => 'Mac'],
            ['id' => 3, 'name' => 'Linux'],
            ['id' => 48, 'name' => 'PlayStation 4'],
            ['id' => 46, 'name' => 'PlayStation Vita'],
            ['id' => 49, 'name' => 'Xbox One'],
            ['id' => 130, 'name' => 'Nintendo Switch'],
            ['id' => 39, 'name' => 'iOS'],
            ['id' => 34, 'name' => 'Android'],
        ];

        self::assertSame(
            ['Mobile', 'PC', 'PlayStation', 'Switch', 'Xbox'],
            PlatformCategory::families($platforms),
        );
    }

    public function testFoldsTheNoisyLongTail(): void
    {
        $platforms = [
            ['id' => 1, 'name' => 'SteamVR'],
            ['id' => 2, 'name' => 'Oculus Rift'],
            ['id' => 3, 'name' => 'Meta Quest 2'],
            ['id' => 4, 'name' => 'visionOS'],
            ['id' => 5, 'name' => 'Google Stadia'],
            ['id' => 6, 'name' => 'Web browser'],
            ['id' => 7, 'name' => 'MSX2'],
            ['id' => 8, 'name' => 'Amazon Fire TV'],
            ['id' => 9, 'name' => 'Satellaview'],
            ['id' => 10, 'name' => 'PlayStation VR'],
        ];

        self::assertSame(
            ['Cloud', 'MSX', 'Mobile', 'Navigateur', 'PlayStation', 'Super Nintendo', 'VR'],
            PlatformCategory::families($platforms),
        );
    }

    public function testFallsBackToIgdbNameWhenUnmapped(): void
    {
        self::assertSame(['Neo Geo'], PlatformCategory::families([['id' => 80, 'name' => 'Neo Geo']]));
    }

    public function testEmptyInputYieldsEmptyList(): void
    {
        self::assertSame([], PlatformCategory::families([]));
    }
}
