<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Application\DiscordBotClientInterface;

final readonly class NullDiscordBotClient implements DiscordBotClientInterface
{
    public function assignRole(string $guildId, string $discordUserId, string $roleId): void
    {
    }

    public function removeRole(string $guildId, string $discordUserId, string $roleId): void
    {
    }

    public function fetchGuildInfo(string $guildId): array
    {
        return ['online' => false];
    }
}
