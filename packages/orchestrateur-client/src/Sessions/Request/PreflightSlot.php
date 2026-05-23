<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Request;

use Archilan\OrchestratorClient\Sessions\Yaml\PlayerYaml;

final readonly class PreflightSlot implements \JsonSerializable
{
    /**
     * @param SlotOption[] $options
     */
    public function __construct(
        public string $slotId,
        public string $playerName = '',
        public string $archipelagoGameName = '',
        public array $options = [],
        public string $apworldStorageKey = '',
        public ?PlayerYaml $playerYaml = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'slotId' => $this->slotId,
            'playerName' => $this->playerName,
            'archipelagoGameName' => $this->archipelagoGameName,
            'options' => array_map(fn (SlotOption $o) => $o->jsonSerialize(), $this->options),
            'apworldStorageKey' => $this->apworldStorageKey,
            'playerYaml' => $this->playerYaml?->toYamlString() ?? '',
        ], fn (mixed $v) => '' !== $v && [] !== $v);
    }
}
