<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Request;

final readonly class ConfigureRequest implements \JsonSerializable
{
    /**
     * @param ConfigureSlot[] $slots
     */
    public function __construct(public array $slots)
    {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'slots' => array_map(fn (ConfigureSlot $s) => $s->jsonSerialize(), $this->slots),
        ];
    }
}
