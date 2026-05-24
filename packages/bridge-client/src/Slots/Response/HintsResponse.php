<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Slots\Response;

final readonly class HintsResponse
{
    /**
     * @param Hint[] $hints
     */
    public function __construct(
        public int $slot,
        public array $hints,
        public int $hintsUsed,
        public int $hintPointsAvailable,
        public int $hintCost,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $hints = [];
        foreach (is_array($data['hints'] ?? null) ? $data['hints'] : [] as $hint) {
            if (is_array($hint)) {
                /** @var array<string, mixed> $hint */
                $hints[] = Hint::fromArray($hint);
            }
        }

        return new self(
            slot:                is_int($data['slot'] ?? null) ? $data['slot'] : 0,
            hints:               $hints,
            hintsUsed:           is_int($data['hintsUsed'] ?? null) ? $data['hintsUsed'] : 0,
            hintPointsAvailable: is_int($data['hintPointsAvailable'] ?? null) ? $data['hintPointsAvailable'] : 0,
            hintCost:            is_int($data['hintCost'] ?? null) ? $data['hintCost'] : 0,
        );
    }
}
