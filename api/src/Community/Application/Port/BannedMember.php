<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

/**
 * A linked account banned on the site (story 39.7), whose Discord ban is followed.
 */
final readonly class BannedMember
{
    public function __construct(
        public string $userId,
        public string $discordId,
    ) {
    }
}
