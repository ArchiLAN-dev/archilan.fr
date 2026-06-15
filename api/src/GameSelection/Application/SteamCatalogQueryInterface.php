<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

interface SteamCatalogQueryInterface
{
    /**
     * All available/experimental catalog games that have a resolved Steam appid.
     *
     * @return list<array{id: string, name: string, slug: string, coverImageUrl: string|null, availability: string, steamAppId: int}>
     */
    public function allWithSteamAppId(): array;
}
