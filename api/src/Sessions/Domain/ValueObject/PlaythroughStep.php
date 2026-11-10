<?php

declare(strict_types=1);

namespace App\Sessions\Domain\ValueObject;

/**
 * Story 52.7: one line of a spoiler's playthrough - in sphere `sphere`, the location `location` of `finder`'s world
 * holds `item` for `receiver` (player names as Archipelago knows them).
 */
final readonly class PlaythroughStep
{
    public function __construct(
        public int $sphere,
        public string $finder,
        public string $location,
        public string $receiver,
        public string $item,
    ) {
    }
}
