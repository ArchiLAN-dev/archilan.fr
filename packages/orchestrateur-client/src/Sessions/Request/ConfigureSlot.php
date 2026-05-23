<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Request;

use Archilan\OrchestratorClient\Sessions\Yaml\PlayerYaml;

final readonly class ConfigureSlot implements \JsonSerializable
{
    public function __construct(
        public string $apworldHash,
        public PlayerYaml $playerYaml,
    ) {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $apworldHash)) {
            throw new \InvalidArgumentException('apworldHash must be a 64-character lowercase hex string (SHA-256).');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'apworldHash' => $this->apworldHash,
            'playerYaml' => $this->playerYaml->toYamlString(),
        ];
    }
}
