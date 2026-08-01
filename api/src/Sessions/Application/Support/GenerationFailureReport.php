<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

/**
 * Result of parsing a generation crash log: the extracted findings plus the log cleaned of
 * known-benign noise (what gets stored and shown as technical detail).
 */
final readonly class GenerationFailureReport
{
    /**
     * @param list<GenerationFailureFinding> $findings
     */
    public function __construct(
        public array $findings,
        public string $cleanedLog,
    ) {
    }
}
