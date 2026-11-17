<?php

declare(strict_types=1);

namespace App\Trackers\Domain\Enum;

/** How a pack variant fared when an admin tried it on the site (story 54.8). */
enum TrackerPackCompatibilityStatus: string
{
    /** Loaded with no error and nothing missing. */
    case Ok = 'ok';
    /** Loaded, but the pack reported errors or uses functions the site does not have. */
    case Warnings = 'warnings';
    /** Did not load. */
    case Failed = 'failed';
}
