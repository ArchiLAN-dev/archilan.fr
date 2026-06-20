<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\CommunityXp;
use App\Community\Domain\Level;
use PHPUnit\Framework\TestCase;

final class LevelTest extends TestCase
{
    public function testZeroXpIsLevelZero(): void
    {
        $level = Level::fromXp(0);

        self::assertSame(0, $level->level);
        self::assertSame(0, $level->xpIntoLevel);
        self::assertSame(100, $level->xpForNextLevel);
    }

    public function testJustBelowFirstThresholdStaysLevelZero(): void
    {
        $level = Level::fromXp(99);

        self::assertSame(0, $level->level);
        self::assertSame(99, $level->xpIntoLevel);
    }

    public function testCrossingThresholdsAdvancesLevels(): void
    {
        // 100 -> level 1 (next costs 200), 300 -> level 2 (next costs 300).
        self::assertSame(1, Level::fromXp(100)->level);
        self::assertSame(0, Level::fromXp(100)->xpIntoLevel);
        self::assertSame(200, Level::fromXp(100)->xpForNextLevel);

        $mid = Level::fromXp(250);
        self::assertSame(1, $mid->level);
        self::assertSame(150, $mid->xpIntoLevel);

        self::assertSame(2, Level::fromXp(300)->level);
    }

    public function testNegativeXpClampsToLevelZero(): void
    {
        self::assertSame(0, Level::fromXp(-50)->level);
    }

    public function testXpFormulaWeightsGoalsAndChecks(): void
    {
        // goals*500 + checks*1 + runs*50 + achievements*100
        self::assertSame(1050, CommunityXp::compute(1, 200, 3, 2));
        self::assertSame(0, CommunityXp::compute(0, 0, 0, 0));
        self::assertSame(0, CommunityXp::compute(-5, -5, -5, -5));
    }
}
