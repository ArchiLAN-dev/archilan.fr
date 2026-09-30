<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Enum;

/**
 * Where a slot stands regarding a BK (story 40.1), read from one entry of the bridge's players push.
 */
enum SlotBlockState
{
    /** No reachable check left, checks still to do, goal not reached: the player is stuck. */
    case Blocked;

    /** At least one check is reachable. */
    case Unblocked;

    /** Reachability not computed yet (`reachable_now` null or absent): neither blocked nor unblocked. */
    case Unknown;

    /** Goal reached, slot released, or every check done: a block no longer means anything. */
    case Settled;
}
