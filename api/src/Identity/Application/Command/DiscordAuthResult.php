<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

final readonly class DiscordAuthResult
{
    public function __construct(
        public DiscordAuthOutcome $outcome,
        public ?string $userId,
    ) {
    }
}
