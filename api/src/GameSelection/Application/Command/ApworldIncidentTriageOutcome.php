<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * Result of an admin action on an apworld incident (story 38.3).
 */
enum ApworldIncidentTriageOutcome: string
{
    case Applied = 'applied';
    case NotFound = 'not_found';
    /** The lifecycle forbids it, such as taking an incident that is already closed. */
    case Forbidden = 'forbidden';
}
