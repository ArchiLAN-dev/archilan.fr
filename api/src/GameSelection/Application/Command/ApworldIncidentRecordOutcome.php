<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * What {@see RecordApworldIncident} did with a reported problem (story 38.1).
 */
enum ApworldIncidentRecordOutcome: string
{
    /** A new incident was opened: the caller alerts on it (story 38.2). */
    case Opened = 'opened';
    /** The active incident for the key counted one more occurrence: no alert. */
    case Recurred = 'recurred';
    /** An admin ignored this key for this apworld hash: nothing recorded. */
    case Suppressed = 'suppressed';
}
