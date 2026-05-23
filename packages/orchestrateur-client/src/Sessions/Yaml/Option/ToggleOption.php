<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Yaml\Option;

final readonly class ToggleOption implements OptionValue
{
    public function __construct(
        public string $key,
        public bool $value,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function jsonSerialize(): int
    {
        return $this->value ? 1 : 0;
    }
}
