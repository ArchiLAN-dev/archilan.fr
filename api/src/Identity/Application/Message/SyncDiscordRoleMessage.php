<?php

declare(strict_types=1);

namespace App\Identity\Application\Message;

final readonly class SyncDiscordRoleMessage
{
    /**
     * @param list<string> $archilanRoles
     */
    public function __construct(
        public string $userId,
        public string $discordUserId,
        public array $archilanRoles,
        public bool $removeAll = false,
    ) {
    }
}
