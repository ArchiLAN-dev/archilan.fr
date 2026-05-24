<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Yaml;

use Archilan\OrchestratorClient\Sessions\Yaml\Option\OptionValue;
use Symfony\Component\Yaml\Yaml;

final readonly class PlayerYaml
{
    /**
     * @param OptionValue[] $options Game-specific and universal options for this slot.
     */
    public function __construct(
        public string $name,
        public string $game,
        public array $options = [],
        public ?string $description = null,
    ) {
    }

    public function toYamlString(): string
    {
        return Yaml::dump($this->toArray(), 4, 2);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'game' => $this->game,
        ];

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        $gameSection = [];
        foreach ($this->options as $option) {
            $gameSection[$option->getKey()] = $option->jsonSerialize();
        }
        if ([] !== $gameSection) {
            $data[$this->game] = $gameSection;
        }

        return $data;
    }
}
