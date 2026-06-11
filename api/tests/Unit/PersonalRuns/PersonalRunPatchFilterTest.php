<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Presentation\PersonalRunPatchController;
use PHPUnit\Framework\TestCase;

final class PersonalRunPatchFilterTest extends TestCase
{
    public function testMatchesOwnSlotInApFilename(): void
    {
        self::assertTrue(PersonalRunPatchController::belongsToOwnSlot(
            'AP_32336784011536737200_P2_masterkafei_LM.aplm',
            ['masterkafei_LM'],
        ));
    }

    public function testFallsBackToPlainStemMatch(): void
    {
        self::assertTrue(PersonalRunPatchController::belongsToOwnSlot('masterkafei_LM.aplm', ['masterkafei_LM']));
    }

    public function testDoesNotMatchOnSlotNameSuffix(): void
    {
        // "LM" is a suffix of "masterkafei_LM" - must NOT grant access (anti-spoiler).
        self::assertFalse(PersonalRunPatchController::belongsToOwnSlot(
            'AP_32336784011536737200_P2_masterkafei_LM.aplm',
            ['LM'],
        ));
    }

    public function testDoesNotMatchOtherPlayersPatch(): void
    {
        self::assertFalse(PersonalRunPatchController::belongsToOwnSlot(
            'AP_32336784011536737200_P1_someone_else.apsmw',
            ['masterkafei_LM'],
        ));
    }

    public function testExcludesMultidata(): void
    {
        self::assertFalse(PersonalRunPatchController::belongsToOwnSlot('AP_323_seed.archipelago', ['seed']));
    }

    public function testExcludesSpoiler(): void
    {
        self::assertFalse(PersonalRunPatchController::belongsToOwnSlot(
            'AP_323_P2_masterkafei_LM_Spoiler.txt',
            ['masterkafei_LM'],
        ));
    }
}
