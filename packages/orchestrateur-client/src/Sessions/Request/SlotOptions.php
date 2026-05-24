<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Request;

use Archilan\OrchestratorClient\Sessions\Yaml\Option\OptionValue;

final readonly class SlotOptions implements \JsonSerializable
{
    /**
     * @param list<OptionValue> $options
     */
    public function __construct(
        public string $playerName,
        public array $options = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $values = [];
        foreach ($this->options as $option) {
            $values[$option->getKey()] = $option->jsonSerialize();
        }

        return [
            'playerName' => $this->playerName,
            'values' => $values,
        ];
    }
}
