<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Yaml\Option;

/**
 * Represents an OptionCounter, ItemDict, or OptionDict option — and universal dict options
 * such as start_inventory (item name → quantity).
 */
final readonly class ItemDictOption implements OptionValue
{
    /**
     * @param array<string, int> $items
     */
    public function __construct(
        public string $key,
        public array $items,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /** @return array<string, int> */
    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
