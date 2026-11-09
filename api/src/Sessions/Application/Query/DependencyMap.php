<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

/**
 * Story 52.7: the dependency map of an event - for each generated session, how many progression items risk waiting
 * on someone absent, and between whom. `declared` is false when nobody declared windows: then nothing is at risk.
 */
final readonly class DependencyMap
{
    /**
     * @param list<DependencyGeneration> $generations
     */
    public function __construct(
        public bool $declared,
        public int $sphereLimit,
        public array $generations,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'declared' => $this->declared,
            'sphereLimit' => $this->sphereLimit,
            'generations' => array_map(static fn (DependencyGeneration $generation): array => [
                'sessionId' => $generation->sessionId,
                'status' => $generation->status,
                'playthrough' => $generation->playthrough,
                'score' => $generation->score,
                'pairs' => array_map(static fn (DependencyPair $pair): array => [
                    'waiting' => $pair->waiting,
                    'holding' => $pair->holding,
                    'items' => $pair->items,
                    'halfDays' => $pair->halfDays,
                ], $generation->pairs),
            ], $this->generations),
        ];
    }
}
