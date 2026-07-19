<?php

declare(strict_types=1);

namespace App\Membership\Application\Port;

interface UserRoleGatewayInterface
{
    /**
     * @return array{discordId: string|null, roles: list<string>}
     */
    public function getUserDiscordInfo(string $userId): array;
}
