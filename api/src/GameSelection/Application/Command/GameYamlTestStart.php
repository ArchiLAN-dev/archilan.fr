<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * Story 9.57: the outcome of starting a YAML test, and the ticket to read its result with when it started.
 */
final readonly class GameYamlTestStart
{
    public function __construct(
        public GameYamlTestOutcome $outcome,
        public ?string $ticket = null,
    ) {
    }
}
