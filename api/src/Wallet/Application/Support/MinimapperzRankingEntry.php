<?php

declare(strict_types=1);

namespace App\Wallet\Application\Support;

/** Story 49.4: one line of the Minimapperz ranking of the event. */
final readonly class MinimapperzRankingEntry
{
    public function __construct(
        public int $rank,
        public string $username,
        public ?string $code,
        public float $score,
        public int $games,
        public int $wins,
    ) {
    }
}
