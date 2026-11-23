<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain\ValueObject;

use App\SessionConfig\Domain\Enum\PlandoOption;
use App\SessionConfig\Domain\Enum\SpoilerLevel;

/**
 * The Archipelago generator options applied when a session's multiworld is generated.
 */
final readonly class SessionGenerationConfig
{
    /** Story 56.6: the k of the image's cap when it is on - a giant world hosts at most twice its fair share. */
    public const float PROGRESSION_CAP_FACTOR = 2.0;

    /** @var list<PlandoOption> */
    public array $plandoOptions;

    /**
     * @param list<PlandoOption> $plandoOptions
     */
    public function __construct(
        array $plandoOptions,
        public bool $race,
        public SpoilerLevel $spoiler,
        /** Story 56.6: cap the share of the others' progression a giant world hosts. Off by default. */
        public bool $progressionCap = false,
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
     * Transport seam for the orchestrateur generate request. The cap is sent only when on: the orchestrator reads
     * its absence as off, and an image older than story 56.6 ignores it.
     *
     * @return array{plandoOptions: list<string>, race: bool, spoiler: int, progressionCap?: float}
     */
    public function toGenerationParams(): array
    {
        $params = [
            'plandoOptions' => array_map(static fn (PlandoOption $o): string => $o->value, $this->plandoOptions),
            'race' => $this->race,
            'spoiler' => $this->spoiler->value,
        ];
        if ($this->progressionCap) {
            $params['progressionCap'] = self::PROGRESSION_CAP_FACTOR;
        }

        return $params;
    }

    /**
     * The canonical config form (admin and owner forms).
     *
     * @return array{plandoOptions: list<string>, race: bool, spoiler: int, progressionCap: bool}
     */
    public function toArray(): array
    {
        return [
            'plandoOptions' => array_map(static fn (PlandoOption $o): string => $o->value, $this->plandoOptions),
            'race' => $this->race,
            'spoiler' => $this->spoiler->value,
            'progressionCap' => $this->progressionCap,
        ];
    }
}
