<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Yaml\Option;

/**
 * Represents a Choice, TextChoice, or FreeText option with a single direct value.
 * For weighted choices use WeightedOption instead.
 */
final readonly class ChoiceOption implements OptionValue
{
    public function __construct(
        public string $key,
        public string $value,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
