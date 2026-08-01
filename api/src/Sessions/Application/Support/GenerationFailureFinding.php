<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

/**
 * One failure extracted from a generation crash log: the message the world/generator raised,
 * attributed to the slot the log names (null when the log does not name one).
 */
final readonly class GenerationFailureFinding
{
    public function __construct(
        public ?string $slotName,
        public string $message,
    ) {
    }
}
