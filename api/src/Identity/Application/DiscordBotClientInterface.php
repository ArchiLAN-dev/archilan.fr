<?php

declare(strict_types=1);

namespace App\Identity\Application;

interface DiscordBotClientInterface
{
    public function assignRole(string $guildId, string $discordUserId, string $roleId): void;

    public function removeRole(string $guildId, string $discordUserId, string $roleId): void;

    /** @return array<string, mixed> */
    public function fetchGuildInfo(string $guildId): array;
}
