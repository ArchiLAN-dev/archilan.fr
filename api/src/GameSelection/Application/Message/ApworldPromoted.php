<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Message;

use App\GameSelection\Application\Command\ApworldPromotion;

/**
 * A game switched to a new apworld (story 38.6), published for the contexts that hold slots of that game
 * (story 38.7): personal runs and event registrations bring their not-yet-launched slots along.
 *
 * Carries what the game no longer knows once it has switched - its previous hash and default YAML.
 */
final readonly class ApworldPromoted
{
    public function __construct(
        public string $gameId,
        public ?string $previousHash,
        public string $newHash,
        public ?string $previousDefaultYaml,
    ) {
    }

    public static function of(ApworldPromotion $promotion): self
    {
        return new self($promotion->gameId, $promotion->previousHash, $promotion->newHash, $promotion->previousDefaultYaml);
    }
}
