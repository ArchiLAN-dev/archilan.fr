<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

/**
 * Story 56.2: a slot's estimated duration, with what it rests on.
 *
 * `hours` is null when the pace is unknown or the slot's size is not measured.
 */
final readonly class SlotDuration
{
    public function __construct(
        public ?float $hours,
        public PaceSource $source,
        /** Slots played to their goal behind a measured pace; 0 otherwise. */
        public int $slots,
        public ?float $checksPerHour,
        public float $playerFactor,
    ) {
    }

    /** @return array{hours: float|null, source: string, slots: int, checksPerHour: float|null, playerFactor: float} */
    public function payload(): array
    {
        return [
            'hours' => null === $this->hours ? null : round($this->hours, 2),
            'source' => $this->source->value,
            'slots' => $this->slots,
            'checksPerHour' => null === $this->checksPerHour ? null : round($this->checksPerHour, 1),
            'playerFactor' => round($this->playerFactor, 2),
        ];
    }
}
