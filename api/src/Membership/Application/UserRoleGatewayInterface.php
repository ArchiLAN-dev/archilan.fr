<?php

declare(strict_types=1);

namespace App\Membership\Application;

interface UserRoleGatewayInterface
{
    /**
     * @return array{discordId: string|null, roles: list<string>}
     */
    public function getUserDiscordInfo(string $userId): array;
}
