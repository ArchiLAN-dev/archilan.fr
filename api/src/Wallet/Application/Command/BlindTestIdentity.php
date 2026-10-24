<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

/**
 * Who the blind test app may let play (story 41.45): the member's id and name, the event, the `state` of the login
 * the app started (it checks it is its own), and until when (the end of the event).
 */
final readonly class BlindTestIdentity
{
    public function __construct(
        public string $userId,
        public string $eventId,
        public string $displayName,
        public string $state,
        public int $expiresAt,
    ) {
    }
}
