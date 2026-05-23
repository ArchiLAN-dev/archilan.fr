<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Yaml\Option;

/**
 * Represents a Range or NamedRange option.
 * The value can be an integer or a special string: random, random-low, random-middle,
 * random-high, or random-range-{min}-{max}.
 */
final readonly class RangeOption implements OptionValue
{
    public function __construct(
        public string $key,
        public int|string $value,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function jsonSerialize(): int|string
    {
        return $this->value;
    }
}
