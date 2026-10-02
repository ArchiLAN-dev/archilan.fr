<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * Story 38.14: the outcome of starting a YAML test, and the id to poll it with when it started.
 */
final readonly class ApworldCandidateYamlTestStart
{
    public function __construct(
        public ApworldCandidateYamlTestOutcome $outcome,
        public ?string $jobId = null,
    ) {
    }
}
