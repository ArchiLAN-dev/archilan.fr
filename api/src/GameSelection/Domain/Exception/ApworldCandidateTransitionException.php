<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Exception;

use App\GameSelection\Domain\Enum\ApworldCandidateStatus;

/**
 * A transition that the candidate lifecycle forbids, such as rejecting an apworld already promoted
 * (story 38.6).
 */
final class ApworldCandidateTransitionException extends \DomainException
{
    public static function from(string $candidateId, ApworldCandidateStatus $status, string $transition): self
    {
        return new self(sprintf('Candidate %s is %s and cannot be %s.', $candidateId, $status->value, $transition));
    }
}
