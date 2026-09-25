<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * Result of {@see SubmitApworldCandidate::submit()}: the candidate now in test, or why there is none.
 */
final readonly class ApworldCandidateSubmission
{
    /**
     * @param list<string> $errors human-readable, empty on success
     */
    public function __construct(
        public bool $gameFound,
        public ?string $candidateId,
        public array $errors = [],
    ) {
    }
}
