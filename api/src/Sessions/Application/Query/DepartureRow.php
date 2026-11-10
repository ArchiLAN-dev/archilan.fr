<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

/** Story 52.6: a departure to handle, as the deciders see it. `origin` is `player` or `schedule`. */
final readonly class DepartureRow
{
    public function __construct(
        public string $id,
        public int $slotIndex,
        public string $slotName,
        public string $origin,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
