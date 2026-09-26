<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Enum;

/**
 * Where an apworld candidate stands (story 38.6). Only `Testing` is waiting for a decision; the other
 * three are history, kept so that a rejected version is never retried automatically.
 */
enum ApworldCandidateStatus: string
{
    case Testing = 'testing';
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
