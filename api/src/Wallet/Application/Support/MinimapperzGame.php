<?php

declare(strict_types=1);

namespace App\Wallet\Application\Support;

/** Story 49.3: a finished Minimapperz game of the event. */
final readonly class MinimapperzGame
{
    /**
     * @param list<MinimapperzGamePlayer> $players
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $playedAt,
        public int $rounds,
        public array $players,
    ) {
    }
}
