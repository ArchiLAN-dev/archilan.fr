<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Yaml\Option;

/**
 * Represents a Range or NamedRange option.
 * The value can be an integer, a special string (random, random-low, random-middle,
 * random-high, random-range-{min}-{max}), or a list of Weighted for a distribution.
 */
final readonly class RangeOption implements OptionValue
{
    /**
     * @param int|string|list<Weighted> $value
     */
    public function __construct(
        public string $key,
        public int|string|array $value,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /** @return int|string|array<string, int> */
    public function jsonSerialize(): int|string|array
    {
        if (\is_array($this->value)) {
            $weights = [];
            foreach ($this->value as $w) {
                $weights[(string) $w->value] = $w->weight;
            }

            return $weights;
        }

        return $this->value;
    }
}
