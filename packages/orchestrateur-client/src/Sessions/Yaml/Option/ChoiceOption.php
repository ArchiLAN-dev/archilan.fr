<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Yaml\Option;

final readonly class ChoiceOption implements OptionValue
{
    /**
     * @param string|list<Weighted> $value Pass a string for a fixed value, or a list of Weighted
     *                                     for a probabilistic distribution.
     */
    public function __construct(
        public string $key,
        public string|array $value,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /** @return string|array<string, int> */
    public function jsonSerialize(): string|array
    {
        if (\is_string($this->value)) {
            return $this->value;
        }

        $weights = [];
        foreach ($this->value as $w) {
            $weights[(string) $w->value] = $w->weight;
        }

        return $weights;
    }
}
