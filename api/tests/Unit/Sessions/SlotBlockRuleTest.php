<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Domain\Entity\SlotBlockEpisode;
use App\Sessions\Domain\Enum\SlotBlockDecision;
use App\Sessions\Domain\Enum\SlotBlockState;
use App\Sessions\Domain\Service\SlotBlockRule;
use PHPUnit\Framework\TestCase;

/**
 * Story 40.1. The same BK rule as the progress grid's badge, and when leaving one is worth a
 * notification: only after a real block (2 minutes), once per episode.
 */
final class SlotBlockRuleTest extends TestCase
{
    public function testNoReachableCheckLeftWithChecksToDoIsBlocked(): void
    {
        self::assertSame(SlotBlockState::Blocked, SlotBlockRule::stateOf($this->slot(reachableNow: 0), false));
    }

    public function testAReachableCheckIsUnblocked(): void
    {
        self::assertSame(SlotBlockState::Unblocked, SlotBlockRule::stateOf($this->slot(reachableNow: 3), false));
    }

    public function testNotYetComputedIsUnknownNotBlocked(): void
    {
        self::assertSame(SlotBlockState::Unknown, SlotBlockRule::stateOf($this->slot(reachableNow: null), false));
        $withoutKey = $this->slot(reachableNow: 0);
        unset($withoutKey['reachable_now']);
        self::assertSame(SlotBlockState::Unknown, SlotBlockRule::stateOf($withoutKey, false));
    }

    public function testGoalReleaseOrAllChecksDoneSettleTheSlot(): void
    {
        self::assertSame(SlotBlockState::Settled, SlotBlockRule::stateOf($this->slot(reachableNow: 0, clientStatus: 30), false));
        self::assertSame(SlotBlockState::Settled, SlotBlockRule::stateOf($this->slot(reachableNow: 0), true));
        self::assertSame(SlotBlockState::Settled, SlotBlockRule::stateOf($this->slot(reachableNow: 0, checksDone: 47), false));
    }

    public function testTheBridgeObserverSlotIsNotAPlayer(): void
    {
        self::assertFalse(SlotBlockRule::isPlayerSlot('Bridge'));
        self::assertFalse(SlotBlockRule::isPlayerSlot(''));
        self::assertTrue(SlotBlockRule::isPlayerSlot('Alice_HK1'));
    }

    public function testABlockOpensAnEpisodeAndNothingElseDoes(): void
    {
        $now = new \DateTimeImmutable('2026-09-29T10:00:00+00:00');

        self::assertSame(SlotBlockDecision::Open, SlotBlockRule::decide(null, SlotBlockState::Blocked, $now));
        self::assertSame(SlotBlockDecision::Ignore, SlotBlockRule::decide(null, SlotBlockState::Unblocked, $now));
        self::assertSame(SlotBlockDecision::Ignore, SlotBlockRule::decide(null, SlotBlockState::Unknown, $now));
        self::assertSame(SlotBlockDecision::Ignore, SlotBlockRule::decide(null, SlotBlockState::Settled, $now));
    }

    public function testAnOpenEpisodeSurvivesABlockAndIsSuspendedByTheBridgeRestarting(): void
    {
        $episode = $this->episode('2026-09-29T10:00:00+00:00');
        $later = new \DateTimeImmutable('2026-09-29T10:10:00+00:00');

        self::assertSame(SlotBlockDecision::Keep, SlotBlockRule::decide($episode, SlotBlockState::Blocked, $later));
        // Story 40.3: an unknown state is the bridge starting again, from the last save. Story 52.4: suspended.
        self::assertSame(SlotBlockDecision::Suspend, SlotBlockRule::decide($episode, SlotBlockState::Unknown, $later));
    }

    public function testASuspendedBlockResumesOnlyWhenNothingWasReceivedInBetween(): void
    {
        $later = new \DateTimeImmutable('2026-09-29T11:00:00+00:00');
        $episode = $this->episode('2026-09-29T10:00:00+00:00', items: 8);
        $episode->suspend(new \DateTimeImmutable('2026-09-29T10:30:00+00:00'));

        self::assertSame(SlotBlockDecision::Suspend, SlotBlockRule::decide($episode, SlotBlockState::Unknown, $later, null, 8));
        self::assertSame(SlotBlockDecision::Resume, SlotBlockRule::decide($episode, SlotBlockState::Blocked, $later, null, 8));
        self::assertSame(SlotBlockDecision::Reopen, SlotBlockRule::decide($episode, SlotBlockState::Blocked, $later, null, 9));
        self::assertSame(SlotBlockDecision::Reopen, SlotBlockRule::decide($episode, SlotBlockState::Blocked, $later), 'unknown items: no proof it is the same block');
        // The first state after a restart is no way out of a block.
        self::assertSame(SlotBlockDecision::CloseSilently, SlotBlockRule::decide($episode, SlotBlockState::Unblocked, $later, null, 8));
        self::assertSame(SlotBlockDecision::CloseSilently, SlotBlockRule::decide($episode, SlotBlockState::Settled, $later, null, 8));
    }

    public function testABlockNotConfirmedSinceTheLastStopIsTreatedAsSuspended(): void
    {
        // The bridge's unknown state may be missed: the session's last stop says the same.
        $episode = $this->episode('2026-09-29T10:00:00+00:00', items: 8);
        $stoppedAt = new \DateTimeImmutable('2026-09-29T10:30:00+00:00');
        $later = new \DateTimeImmutable('2026-09-29T11:00:00+00:00');

        self::assertSame(SlotBlockDecision::Resume, SlotBlockRule::decide($episode, SlotBlockState::Blocked, $later, $stoppedAt, 8));
        self::assertSame(SlotBlockDecision::CloseSilently, SlotBlockRule::decide($episode, SlotBlockState::Unblocked, $later, $stoppedAt, 8));

        $episode->resume($later, 8);
        $after = $later->modify('+5 minutes');
        self::assertSame(SlotBlockDecision::Keep, SlotBlockRule::decide($episode, SlotBlockState::Blocked, $after, $stoppedAt, 8));
        self::assertSame(SlotBlockDecision::CloseAndNotify, SlotBlockRule::decide($episode, SlotBlockState::Unblocked, $after, $stoppedAt, 9), 'confirmed after the stop: leaving it is real');
    }

    public function testLeavingARealBlockNotifies(): void
    {
        $episode = $this->episode('2026-09-29T10:00:00+00:00');

        self::assertSame(
            SlotBlockDecision::CloseAndNotify,
            SlotBlockRule::decide($episode, SlotBlockState::Unblocked, new \DateTimeImmutable('2026-09-29T10:02:00+00:00')),
        );
    }

    public function testLeavingAShortBlockClosesWithoutNotifying(): void
    {
        $episode = $this->episode('2026-09-29T10:00:00+00:00');

        self::assertSame(
            SlotBlockDecision::CloseSilently,
            SlotBlockRule::decide($episode, SlotBlockState::Unblocked, new \DateTimeImmutable('2026-09-29T10:01:59+00:00')),
        );
    }

    public function testASettledSlotClosesItsEpisodeWithoutNotifying(): void
    {
        $episode = $this->episode('2026-09-29T10:00:00+00:00');

        self::assertSame(
            SlotBlockDecision::CloseSilently,
            SlotBlockRule::decide($episode, SlotBlockState::Settled, new \DateTimeImmutable('2026-09-29T11:00:00+00:00')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function slot(?int $reachableNow, int $clientStatus = 20, int $checksDone = 12): array
    {
        return [
            'slot_name' => 'Alice_HK1',
            'checks_done' => $checksDone,
            'checks_total' => 47,
            'items_received' => 8,
            'client_status' => $clientStatus,
            'goal_reached_at' => null,
            'reachable_now' => $reachableNow,
        ];
    }

    private function episode(string $since, ?int $items = null): SlotBlockEpisode
    {
        return SlotBlockEpisode::open('session-1', '1', 'Alice_HK1', new \DateTimeImmutable($since), $items);
    }
}
