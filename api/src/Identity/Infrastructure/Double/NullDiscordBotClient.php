<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Double;

use App\Identity\Application\Port\DiscordBotClientInterface;

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
