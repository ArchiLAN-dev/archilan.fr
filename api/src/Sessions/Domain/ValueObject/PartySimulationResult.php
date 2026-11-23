<?php

declare(strict_types=1);

namespace App\Sessions\Domain\ValueObject;

/**
 * Story 56.4: a simulated party - each slot's course, when the last one ends and which slot that is (the critical
 * slot, the one the party waits for).
 */
final readonly class PartySimulationResult
{
    /**
     * @param array<string, SimulatedSlot> $slots slot name => its course
     */
    public function __construct(
        public array $slots,
        public float $endHours,
        public ?string $criticalSlot,
    ) {
    }
}
