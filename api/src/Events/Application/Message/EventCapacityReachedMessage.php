<?php

declare(strict_types=1);

namespace App\Events\Application\Message;

final readonly class EventCapacityReachedMessage
{
    public function __construct(
        public string $eventId,
        public string $eventTitle,
        public int $capacity,
    ) {
    }
}
