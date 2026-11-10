<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Enum;

/**
 * What a players push does to a slot's block episode (story 40.1).
 */
enum SlotBlockDecision
{
    /** The slot just got blocked: remember since when. */
    case Open;

    /** Still blocked: the episode goes on. */
    case Keep;

    /** The episode ends without a notification (too short, or the slot is settled). */
    case CloseSilently;

    /** The slot left a real block: the episode ends and its players are told. */
    case CloseAndNotify;

    /** No episode and nothing to open. */
    case Ignore;

    /** Story 52.4: the server restarted - the episode waits for the slot's next state. */
    case Suspend;

    /** Story 52.4: blocked again after a restart, nothing received in between - the same block goes on. */
    case Resume;

    /** Story 52.4: blocked again after a restart, but the slot received something - a new block begins. */
    case Reopen;
}
