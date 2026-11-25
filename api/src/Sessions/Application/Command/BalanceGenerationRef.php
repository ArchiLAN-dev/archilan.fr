<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

/**
 * The report and the generation behind a dry generation's orchestrator session id (fixed after the 1.1.0 review).
 * A generation started before 1.1.1 carries no attempt.
 */
final readonly class BalanceGenerationRef
{
    public function __construct(
        public string $reportId,
        public ?string $attempt,
    ) {
    }
}
