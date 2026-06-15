<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

interface SteamWebApiClientInterface
{
    /**
     * Resolve a Steam vanity name to a SteamID64, or null when it does not resolve.
     *
     * @throws SteamApiException on a Steam API / transport error
     */
    public function resolveVanityUrl(string $vanity): ?string;

    /**
     * Fetch the appids owned by a SteamID64. A private "Game details" profile
     * (Steam returns no game list) maps to visibility 'private' with no appids.
     *
     * @return array{visibility: 'public'|'private', appIds: list<int>}
     *
     * @throws SteamApiException on a Steam API / transport error
     */
    public function fetchOwnedAppIds(string $steamId64): array;
}
