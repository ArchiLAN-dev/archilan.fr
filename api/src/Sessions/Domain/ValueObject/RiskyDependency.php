<?php

declare(strict_types=1);

namespace App\Sessions\Domain\ValueObject;

/**
 * Story 52.7: a progression item `waiting` needs early (in their first spheres) lies in `holding`'s world, and some
 * half-days have `waiting` there while `holding` is not (`halfDays`: their indexes in the event's half-days).
 * `waiting` and `holding` are people (registrations), `receiver` and `finder` their slots.
 */
final readonly class RiskyDependency
{
    /**
     * @param list<int> $halfDays
     */
    public function __construct(
        public string $waiting,
        public string $holding,
        public string $receiver,
        public string $finder,
        public string $item,
        public string $location,
        public int $sphere,
        public array $halfDays,
    ) {
    }
}
