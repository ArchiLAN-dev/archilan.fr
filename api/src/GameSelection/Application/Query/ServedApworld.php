<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

/**
 * A game and the apworld hash it currently serves to players (story 38.1).
 */
final readonly class ServedApworld
{
    public function __construct(
        public string $gameId,
        public string $apworldHash,
        // Story 38.9: a disabled game is left out of the rolling test.
        public bool $disabled = false,
        // Story 38.30: its test generations add a partner player (a meta-game cannot generate solo).
        public bool $testWithPartner = false,
    ) {
    }
}
