<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Message;

/**
 * A failed generation named the file it needed (story 44.3): mark it required on the game that asked.
 * Carries the failure's `requiredFile` record, public apworld metadata - never a file.
 */
final readonly class RecordGenerationRequiredFileJob
{
    /**
     * @param list<string>         $candidateGameIds the games the failed generation held
     * @param array<string, mixed> $requiredFile     the `requiredFile` record of the failure line
     */
    public function __construct(
        public array $candidateGameIds,
        public array $requiredFile,
    ) {
    }
}
