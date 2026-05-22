<?php

declare(strict_types=1);

namespace App\Identity\Application;

interface DiscordBotStatusQueryInterface
{
    /**
     * @return array{botOnline: bool, guildName: string|null, memberCount: int|null, activeMemberCount: int, managedRoleIds: list<string>, inviteUrl: string|null}
     */
    public function query(): array;
}
