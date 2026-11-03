<?php

declare(strict_types=1);

namespace App\Wallet\Application\Support;

/** Story 49.3: one player of a finished Minimapperz game, with the code they joined the LAN with (null without one). */
final readonly class MinimapperzGamePlayer
{
    public function __construct(
        public string $username,
        public ?string $code,
        public int $points,
        public int $place,
        public bool $won,
    ) {
    }
}
