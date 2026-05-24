<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Request;

use Archilan\OrchestratorClient\Sessions\Yaml\PlayerYaml;

final readonly class ConfigureSlot implements \JsonSerializable
{
    private function __construct(
        public string $apworldHash,
        private ?PlayerYaml $playerYaml,
        private ?SlotOptions $options,
    ) {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $apworldHash)) {
            throw new \InvalidArgumentException('apworldHash must be a 64-character lowercase hex string (SHA-256).');
        }
    }

    public static function fromYaml(string $apworldHash, PlayerYaml $playerYaml): self
    {
        return new self($apworldHash, $playerYaml, null);
    }

    public static function fromOptions(string $apworldHash, SlotOptions $options): self
    {
        return new self($apworldHash, null, $options);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        if (null !== $this->options) {
            return [
                'apworldHash' => $this->apworldHash,
                'options' => $this->options,
            ];
        }

        \assert(null !== $this->playerYaml, 'playerYaml must be set in YAML mode');

        return [
            'apworldHash' => $this->apworldHash,
            'playerYaml' => $this->playerYaml->toYamlString(),
        ];
    }
}
