<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Yaml\Option;

final readonly class ToggleOption implements OptionValue
{
    /**
     * @param bool|list<Weighted> $value Pass a bool for a fixed value, or a list of Weighted
     *                                   for a probabilistic distribution (keys: 'true'/'false').
     */
    public function __construct(
        public string $key,
        public bool|array $value,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /** @return int|array<string, int> */
    public function jsonSerialize(): int|array
    {
        if (\is_bool($this->value)) {
            return $this->value ? 1 : 0;
        }

        $weights = [];
        foreach ($this->value as $w) {
            $weights[$w->value ? 'true' : 'false'] = $w->weight;
        }

        return $weights;
    }
}
