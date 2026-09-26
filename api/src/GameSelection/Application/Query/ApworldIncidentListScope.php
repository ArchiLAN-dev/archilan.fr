<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

/**
 * Which incidents the health page lists (story 38.3).
 */
enum ApworldIncidentListScope: string
{
    /** Open or acknowledged, oldest first: what still needs someone. */
    case Active = 'active';
    /** Resolved or ignored, most recently closed first: the history. */
    case Closed = 'closed';
    /** Active first, then the history. */
    case All = 'all';
}
