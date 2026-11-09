<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

/**
 * Story 52.7: « Alice : 3 objets chez Bob, absent sam. matin » - the early progression items of `waiting` that lie in
 * `holding`'s world, and the half-days when `waiting` is there and `holding` is not (« sam. matin »...).
 */
final readonly class DependencyPair
{
    /**
     * @param list<string> $items
     * @param list<string> $halfDays
     */
    public function __construct(
        public string $waiting,
        public string $holding,
        public array $items,
        public array $halfDays,
    ) {
    }
}
