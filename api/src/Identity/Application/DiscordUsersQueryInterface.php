<?php

declare(strict_types=1);

namespace App\Identity\Application;

interface DiscordUsersQueryInterface
{
    /**
     * @return array{data: list<array{id: string, email: string, displayName: string|null, roles: list<string>, discordId: string, discordUsername: string|null, discordRoleSyncedAt: string|null, discordSyncError: string|null}>, meta: array{page: int, limit: int, total: int}}
     */
    public function query(int $page = 1, int $limit = 50): array;
}
