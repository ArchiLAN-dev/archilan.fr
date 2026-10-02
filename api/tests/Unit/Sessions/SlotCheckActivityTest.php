<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Domain\Service\SlotCheckActivity;
use PHPUnit\Framework\TestCase;

/**
 * Story 30.45. Which slots just made a check, from two successive players pushes of the bridge.
 */
final class SlotCheckActivityTest extends TestCase
{
    public function testASlotWhoseChecksGrewJustMadeACheck(): void
    {
        $previous = ['slots' => [
            '1' => ['slot_name' => 'Jean', 'checks_done' => 10],
            '2' => ['slot_name' => 'Ced', 'checks_done' => 4],
        ]];
        $current = ['slots' => [
            '1' => ['slot_name' => 'Jean', 'checks_done' => 12],
            '2' => ['slot_name' => 'Ced', 'checks_done' => 4],
        ]];

        self::assertSame(['Jean'], SlotCheckActivity::slotsWithNewChecks($previous, $current));
    }

    public function testTheFirstPushOfASessionDatesNothing(): void
    {
        $current = ['slots' => ['1' => ['slot_name' => 'Jean', 'checks_done' => 12]]];

        self::assertSame([], SlotCheckActivity::slotsWithNewChecks(null, $current));
    }

    public function testASlotAbsentFromThePreviousPushIsNotDated(): void
    {
        // No reference to compare with: a slot appearing late must not look like it just played.
        $previous = ['slots' => ['1' => ['slot_name' => 'Jean', 'checks_done' => 1]]];
        $current = ['slots' => [
            '1' => ['slot_name' => 'Jean', 'checks_done' => 1],
            '2' => ['slot_name' => 'Ced', 'checks_done' => 30],
        ]];

        self::assertSame([], SlotCheckActivity::slotsWithNewChecks($previous, $current));
    }

    public function testMalformedPayloadsAreIgnored(): void
    {
        self::assertSame([], SlotCheckActivity::slotsWithNewChecks(['slots' => 'x'], ['slots' => null]));
        self::assertSame([], SlotCheckActivity::slotsWithNewChecks(
            ['slots' => ['1' => ['slot_name' => 'Jean', 'checks_done' => '3']]],
            ['slots' => ['1' => ['slot_name' => 'Jean', 'checks_done' => 5]]],
        ));
    }
}
