<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Yaml\Option;

/**
 * Pairs a concrete value with a relative weight for probabilistic option expressions.
 *
 * Used as elements of the array form of ToggleOption, ChoiceOption, and RangeOption:
 *   new ToggleOption('swordless', [new Weighted(true, 70), new Weighted(false, 30)])
 */
final readonly class Weighted
{
    public function __construct(
        public bool|int|string $value,
        public int $weight,
    ) {
    }
}
