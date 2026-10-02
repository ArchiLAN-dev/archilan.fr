<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Enum;

/**
 * Where an apworld candidate stands (story 38.6). `Testing` waits for its verdict and `Awaiting` for an admin
 * (story 38.14); the others are history, kept so that a rejected version is never retried automatically.
 */
enum ApworldCandidateStatus: string
{
    case Testing = 'testing';
    /**
     * Story 38.14: held for the admin's approval, its test passed. It neither expires nor goes online by itself:
     * the game keeps serving its version until an admin puts this one online.
     */
    case Awaiting = 'awaiting';
    case Promoted = 'promoted';
    case Rejected = 'rejected';
    /** Replaced by a newer submission for the same game before any verdict. */
    case Superseded = 'superseded';
    /**
     * No verdict within the deadline (story 38.6 review): an orchestrator problem - a queue, a restart -
     * not a broken release. Unlike a rejection, it does not bar the version from the next nightly try.
     */
    case Expired = 'expired';
}
