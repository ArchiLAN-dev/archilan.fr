<?php

declare(strict_types=1);

namespace App\Sessions\Domain\ValueObject;

/**
 * Story 56.4: how one slot's game unfolds in a party simulation - when it ends, how long it waited for the others'
 * items (BK), and the slots it waited on most, the longest wait first.
 */
final readonly class SimulatedSlot
{
    /**
     * @param array<string, float> $waitsOn slot name => hours waited on it, the longest first
     */
    public function __construct(
        public float $finishHours,
        public float $bkHours,
        public array $waitsOn,
    ) {
    }
}
