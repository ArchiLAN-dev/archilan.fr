<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Message;

/**
 * Announce on the staff channel that a game switched to a new apworld (story 38.6 AC 11). Carries the
 * previous version, which the game no longer knows once it has switched.
 */
final readonly class PostApworldPromotionToStaffChannelJob
{
    public function __construct(
        public string $candidateId,
        public ?string $previousVersion,
    ) {
    }
}
