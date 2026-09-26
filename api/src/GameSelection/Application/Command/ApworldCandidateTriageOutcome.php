<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * Result of an admin action on a game's apworld candidate (story 38.6).
 */
enum ApworldCandidateTriageOutcome: string
{
    case Applied = 'applied';
    /** The game has no candidate in test or rejected. */
    case NoCandidate = 'no_candidate';
    /** The lifecycle forbids it, such as retrying a candidate that is still in test. */
    case Forbidden = 'forbidden';
    /** The orchestrator could not record the override or rerun the test: nothing changed. */
    case RunnerUnavailable = 'runner_unavailable';
}
