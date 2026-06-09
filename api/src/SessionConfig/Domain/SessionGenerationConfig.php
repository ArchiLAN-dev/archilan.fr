<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain;

/**
 * The Archipelago generator options applied when a session's multiworld is generated.
 */
final readonly class SessionGenerationConfig
{
    /** @var list<PlandoOption> */
    public array $plandoOptions;

    /**
     * @param list<PlandoOption> $plandoOptions
     */
    public function __construct(
        array $plandoOptions,
        public bool $race,
        public SpoilerLevel $spoiler,
    ) {
        // Normalise: dedupe while preserving order. Element validity is guaranteed by the
        // PlandoOption type; callers parsing raw input use PlandoOption::fromString first.
        $seen = [];
        $normalized = [];
        foreach ($plandoOptions as $option) {
            if (!isset($seen[$option->value])) {
                $seen[$option->value] = true;
                $normalized[] = $option;
            }
        }
        $this->plandoOptions = $normalized;
    }

    /**
     * Transport seam for the orchestrateur generate request.
     *
     * @return array{plandoOptions: list<string>, race: bool, spoiler: int}
     */
    public function toGenerationParams(): array
    {
        return [
            'plandoOptions' => array_map(static fn (PlandoOption $o): string => $o->value, $this->plandoOptions),
            'race' => $this->race,
            'spoiler' => $this->spoiler->value,
        ];
    }
}
