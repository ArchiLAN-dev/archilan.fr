<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

/**
 * Story 56.2: where a game's pace comes from.
 */
enum PaceSource: string
{
    /** The median of at least three of our slots played to their goal. */
    case Measured = 'measured';
    /** The admin's estimate on the game's sheet. */
    case Admin = 'admin';
    /** Neither: the slot has a size, no duration. */
    case Unknown = 'unknown';
}
