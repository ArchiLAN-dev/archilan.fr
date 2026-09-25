<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * A game switched to a new apworld (story 38.6). The previous hash and default YAML are what story
 * 38.7 needs to bring the slots of runs not yet launched along.
 */
final readonly class ApworldPromotion
{
    /**
     * @param list<string> $resolvedIncidentIds update incidents of the game this promotion settled
     */
    public function __construct(
        public string $candidateId,
        public string $gameId,
        public ?string $previousHash,
        public string $newHash,
        public ?string $previousVersion,
        public ?string $newVersion,
        public ?string $previousDefaultYaml,
        public array $resolvedIncidentIds = [],
    ) {
    }
}
