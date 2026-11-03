<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

/** The outcome of {@see CollectMinimapperzGames}: events read, games seen, pelles credited. */
final readonly class MinimapperzCollection
{
    public function __construct(
        public int $events,
        public int $games,
        public int $credited,
    ) {
    }
}
