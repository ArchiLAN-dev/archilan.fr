<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

/**
 * A member still suspended on the site (story 39.6), as the daily Discord timeout extension reads them.
 */
final readonly class SuspendedMember
{
    public function __construct(
        public string $userId,
        public ?string $discordId,
        public string $suspendedUntil,
        public ?string $reason,
    ) {
    }
}
