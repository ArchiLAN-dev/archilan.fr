<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Yaml\Option;

/**
 * Represents any option value expressed as weighted choices.
 * Each key is a choice name and the value is its relative weight.
 * Works for Choice, Range, Toggle, and any other option type.
 *
 * Example: ['original_dungeon' => 1, 'any_world' => 2] → any_world is twice as likely.
 */
final readonly class WeightedOption implements OptionValue
{
    /**
     * @param array<string, int> $weights
     */
    public function __construct(
        public string $key,
        public array $weights,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /** @return array<string, int> */
    public function jsonSerialize(): array
    {
        return $this->weights;
    }
}
