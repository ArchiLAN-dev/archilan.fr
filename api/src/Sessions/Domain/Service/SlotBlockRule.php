<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Service;

use App\Sessions\Domain\Entity\SlotBlockEpisode;
use App\Sessions\Domain\Enum\SlotBlockDecision;
use App\Sessions\Domain\Enum\SlotBlockState;

/**
 * The BK rule (story 40.1), identical to the badge of the progress grid (`PlayerProgressGrid`):
 * no reachable check left while checks remain and the goal is not reached. And when leaving one is
 * worth telling the player: only after a real block, so the few seconds a fresh item takes to be
 * recomputed never notify anybody.
 */
final class SlotBlockRule
{
    public const int MIN_BLOCKED_SECONDS = 120;

    private const int CLIENT_STATUS_GOAL = 30;
    private const string OBSERVER_SLOT_NAME = 'Bridge';

    /**
     * @param array<array-key, mixed> $slot one entry of the players push `slots` map
     */
    public static function stateOf(array $slot, bool $released): SlotBlockState
    {
        $checksDone = $slot['checks_done'] ?? null;
        $checksTotal = $slot['checks_total'] ?? null;
        $goal = self::CLIENT_STATUS_GOAL === ($slot['client_status'] ?? null);

        if ($released || $goal || (is_int($checksDone) && is_int($checksTotal) && $checksDone >= $checksTotal)) {
            return SlotBlockState::Settled;
        }

        $reachableNow = $slot['reachable_now'] ?? null;
        if (!is_int($reachableNow) || !is_int($checksDone) || !is_int($checksTotal)) {
            return SlotBlockState::Unknown;
        }

        return 0 === $reachableNow ? SlotBlockState::Blocked : SlotBlockState::Unblocked;
    }

    /** The bridge joins every game as an observer slot; it is nobody's to be blocked in. */
    public static function isPlayerSlot(string $slotName): bool
    {
        return '' !== $slotName && self::OBSERVER_SLOT_NAME !== $slotName;
    }

    public static function decide(?SlotBlockEpisode $episode, SlotBlockState $state, \DateTimeImmutable $now): SlotBlockDecision
    {
        if (null === $episode) {
            return SlotBlockState::Blocked === $state ? SlotBlockDecision::Open : SlotBlockDecision::Ignore;
        }

        return match ($state) {
            SlotBlockState::Blocked => SlotBlockDecision::Keep,
            // Story 40.3: the bridge only reports an unknown state when it starts, rebuilt from the last save -
            // what was before no longer holds, and the first recompute must not pass for leaving a block.
            SlotBlockState::Unknown => SlotBlockDecision::CloseSilently,
            SlotBlockState::Settled => SlotBlockDecision::CloseSilently,
            SlotBlockState::Unblocked => $episode->lastedAtLeast(self::MIN_BLOCKED_SECONDS, $now)
                ? SlotBlockDecision::CloseAndNotify
                : SlotBlockDecision::CloseSilently,
        };
    }
}
