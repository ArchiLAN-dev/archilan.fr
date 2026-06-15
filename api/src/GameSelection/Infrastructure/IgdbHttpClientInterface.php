<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

interface IgdbHttpClientInterface
{
    /**
     * @return list<array{igdbId: int, name: string, slug: string, summary: string|null, coverUrl: string|null}>
     */
    public function searchGames(string $query, int $limit = 10, int $offset = 0): array;

    /**
     * Resolve the Steam appid for an IGDB game via the external_games endpoint
     * (Steam category), or null when IGDB has no Steam entry for that game.
     */
    public function fetchSteamAppId(int $igdbId): ?int;

    /**
     * Resolve the platforms an IGDB game released on (raw IGDB platforms), or [] when none.
     *
     * @return list<array{id: int, name: string}>
     */
    public function fetchPlatforms(int $igdbId): array;
}
