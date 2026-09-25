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
}
