<?php

declare(strict_types=1);

namespace App\Community\Domain\Enum;

use App\Sessions\Domain\Enum\SlotBlockState;
use App\Sessions\Domain\Service\SlotBlockRule;

/**
 * Where a member playing stands in the slot their presence shows (story 43.7, rich presence): playing with a
 * progress, in BK, goal reached, or unknown (no snapshot yet, or an imported seed without detailed tracking).
 */
enum PresenceSlotState: string
{
    case Playing = 'playing';
    case Bk = 'bk';
    case Goal = 'goal';
    case Unknown = 'unknown';

    /** Archipelago's client status for a goal reached (CLIENT_GOAL), as the bridge reports it. */
    private const int CLIENT_STATUS_GOAL = 30;

    /**
     * The state and progress (percent of checks done, null when unknown) of a slot. The BK is SlotBlockRule's,
     * the same rule as the badge of the progress grid.
     *
     * @param array<array-key, mixed>|null $snapshotSlot the slot's entry of the bridge's last players push
     *
     * @return array{state: self, percent: int|null}
     */
    public static function of(?array $snapshotSlot, bool $goalReached, bool $tracked): array
    {
        if ($goalReached) {
            return ['state' => self::Goal, 'percent' => null];
        }
        if (!$tracked || null === $snapshotSlot) {
            return ['state' => self::Unknown, 'percent' => null];
        }

        $done = $snapshotSlot['checks_done'] ?? null;
        $total = $snapshotSlot['checks_total'] ?? null;
        $percent = is_int($done) && is_int($total) && $total > 0 ? intdiv(min($done, $total) * 100, $total) : null;

        return match (SlotBlockRule::stateOf($snapshotSlot, false)) {
            // Settled is also every check done without the goal: still playing then, at 100 %.
            SlotBlockState::Settled => self::CLIENT_STATUS_GOAL === ($snapshotSlot['client_status'] ?? null)
                ? ['state' => self::Goal, 'percent' => null]
                : ['state' => self::Playing, 'percent' => $percent],
            SlotBlockState::Blocked => ['state' => self::Bk, 'percent' => $percent],
            SlotBlockState::Unblocked, SlotBlockState::Unknown => null === $percent
                ? ['state' => self::Unknown, 'percent' => null]
                : ['state' => self::Playing, 'percent' => $percent],
        };
    }
}
