<?php

declare(strict_types=1);

namespace App\Wallet\Application\Support;

/** Story 49.3: a player of the Minimapperz event, with the code they joined with (null without one). */
final readonly class MinimapperzPlayer
{
    public function __construct(
        public string $username,
        public ?string $code,
        public int $games,
        public int $wins,
    ) {
    }
}
