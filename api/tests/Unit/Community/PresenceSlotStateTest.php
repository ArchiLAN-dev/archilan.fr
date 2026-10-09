<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\Enum\PresenceSlotState;
use PHPUnit\Framework\TestCase;

final class PresenceSlotStateTest extends TestCase
{
    public function testTheProgressIsThePercentOfChecksDone(): void
    {
        self::assertSame(['state' => PresenceSlotState::Playing, 'percent' => 33], PresenceSlotState::of(['checks_done' => 1, 'checks_total' => 3, 'reachable_now' => 2], false, true));
    }

    public function testNoReachableCheckIsABkWithItsProgress(): void
    {
        self::assertSame(['state' => PresenceSlotState::Bk, 'percent' => 50], PresenceSlotState::of(['checks_done' => 5, 'checks_total' => 10, 'reachable_now' => 0], false, true));
    }

    public function testTheGoalWinsWhetherRecordedOrReported(): void
    {
        self::assertSame(PresenceSlotState::Goal, PresenceSlotState::of(null, true, true)['state']);
        self::assertSame(PresenceSlotState::Goal, PresenceSlotState::of(['checks_done' => 5, 'checks_total' => 10, 'client_status' => 30], false, true)['state']);
    }

    public function testEveryCheckDoneWithoutTheGoalIsStillPlaying(): void
    {
        self::assertSame(['state' => PresenceSlotState::Playing, 'percent' => 100], PresenceSlotState::of(['checks_done' => 10, 'checks_total' => 10, 'client_status' => 20], false, true));
    }

    public function testWithoutSnapshotOrTrackingTheStateIsUnknown(): void
    {
        $unknown = ['state' => PresenceSlotState::Unknown, 'percent' => null];
        self::assertSame($unknown, PresenceSlotState::of(null, false, true));
        self::assertSame($unknown, PresenceSlotState::of(['checks_done' => 5, 'checks_total' => 10, 'reachable_now' => 0], false, false));
        self::assertSame($unknown, PresenceSlotState::of(['slot_name' => 'X'], false, true));
    }

    public function testAReachabilityNotComputedYetStillGivesTheProgress(): void
    {
        self::assertSame(['state' => PresenceSlotState::Playing, 'percent' => 20], PresenceSlotState::of(['checks_done' => 2, 'checks_total' => 10], false, true));
    }
}
