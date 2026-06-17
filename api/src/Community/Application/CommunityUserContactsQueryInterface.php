<?php

declare(strict_types=1);

namespace App\Community\Application;

/**
 * Reads the external account handles needed to resolve an avatar (Discord id, raw Steam profile),
 * sourced from the Identity user row. Keeps `Community` a leaf consumer (no Identity aggregate import).
 */
interface CommunityUserContactsQueryInterface
{
    /**
     * @return array{discordId: string|null, steamProfile: string|null}|null null when the user is unknown
     */
    public function forUser(string $userId): ?array;
}
