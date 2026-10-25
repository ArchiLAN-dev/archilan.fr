<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

/**
 * Who the blind test app may let in (stories 41.45, 41.53): the member's id and name, the event they play (none for
 * an animator), their role (`player` or `animator`), the `state` of the login the app started (it checks it is its
 * own), and until when (the end of the event, or 12 hours for an animator).
 */
final readonly class BlindTestIdentity
{
    public const string PLAYER = 'player';
    public const string ANIMATOR = 'animator';

    public function __construct(
        public string $userId,
        public ?string $eventId,
        public string $displayName,
        public string $state,
        public int $expiresAt,
        public string $role = self::PLAYER,
    ) {
    }
}
