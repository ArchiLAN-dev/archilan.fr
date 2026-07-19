<?php

declare(strict_types=1);

namespace App\Tests\Unit\WeeklyRuns;

use App\WeeklyRuns\Presentation\Controller\WeeklyEntryPatchController;
use PHPUnit\Framework\TestCase;

/**
 * A weekly run is a single shared seed, so every entrant is entitled to its patch(es); only the shared
 * multidata (.archipelago) and spoilers are withheld from the durable archive listing (#262).
 */
final class WeeklyEntryPatchFilterTest extends TestCase
{
    public function testAllowsAPatchFile(): void
    {
        self::assertTrue(WeeklyEntryPatchController::isDownloadablePatch('AP_123_P1_weekly.aplm'));
    }

    public function testExcludesMultidata(): void
    {
        self::assertFalse(WeeklyEntryPatchController::isDownloadablePatch('AP_123_seed.archipelago'));
    }

    public function testExcludesSpoilerCaseInsensitive(): void
    {
        self::assertFalse(WeeklyEntryPatchController::isDownloadablePatch('AP_123_P1_weekly_Spoiler.txt'));
    }
}
