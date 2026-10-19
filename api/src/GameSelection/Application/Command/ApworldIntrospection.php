<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * What the orchestrator's introspection says about a candidate apworld, already normalized (story
 * 38.6 review): read once, before anything moves, then applied by the promotion.
 */
final readonly class ApworldIntrospection
{
    /**
     * @param array<string, array{type: string, min?: int, max?: int, default?: int|string|bool|null, values?: list<string>, keys?: array<string, array{values: list<string>}>}> $optionTypes
     * @param list<string>                                                                                                                                                       $locationNames
     * @param list<string>                                                                                                                                                       $itemNames     story 9.58, empty from an older orchestrator
     * @param array<string, list<string>>                                                                                                                                        $itemGroups
     */
    public function __construct(
        public array $optionTypes,
        public array $locationNames,
        public array $itemNames = [],
        public array $itemGroups = [],
    ) {
    }
}
