<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions\Yaml\Option;

/**
 * Represents an OptionList, OptionSet, or ItemSet option — and universal list options
 * such as local_items, non_local_items, start_hints, exclude_locations, etc.
 */
final readonly class ItemListOption implements OptionValue
{
    /**
     * @param string[] $items
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

    /** @return string[] */
    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
