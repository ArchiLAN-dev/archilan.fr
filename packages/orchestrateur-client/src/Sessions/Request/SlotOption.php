<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Request;

final readonly class SlotOption implements \JsonSerializable
{
    public function __construct(
        public string $key,
        public bool $required,
        public mixed $currentValue,
        public mixed $defaultValue,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'required' => $this->required,
            'currentValue' => $this->currentValue,
            'defaultValue' => $this->defaultValue,
        ];
    }
}
