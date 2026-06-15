<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

final class StubIgdbHttpClient implements IgdbHttpClientInterface
{
    public static bool $authFails = false;
    public static bool $searchFails = false;

    /** @var array<int, int> map of IGDB id => Steam appid */
    public static array $steamAppIds = [1234 => 367520];

    /** @var array<int, list<array{id: int, name: string}>> map of IGDB id => platforms */
    public static array $platforms = [1234 => [['id' => 6, 'name' => 'PC (Microsoft Windows)']]];

    /** @var list<array{igdbId: int, name: string, slug: string, summary: string|null, coverUrl: string|null}> */
    public static array $results = [
        [
            'igdbId' => 1234,
            'name' => 'Hollow Knight',
            'slug' => 'hollow-knight',
            'summary' => 'A challenging 2D action adventure.',
            'coverUrl' => 'https://images.igdb.com/igdb/image/upload/t_cover_big/co1rgi.jpg',
        ],
    ];

    public static function reset(): void
    {
        self::$authFails = false;
        self::$searchFails = false;
        self::$steamAppIds = [1234 => 367520];
        self::$platforms = [1234 => [['id' => 6, 'name' => 'PC (Microsoft Windows)']]];
        self::$results = [
            [
                'igdbId' => 1234,
                'name' => 'Hollow Knight',
                'slug' => 'hollow-knight',
                'summary' => 'A challenging 2D action adventure.',
                'coverUrl' => 'https://images.igdb.com/igdb/image/upload/t_cover_big/co1rgi.jpg',
            ],
        ];
    }

    public function searchGames(string $query, int $limit = 10, int $offset = 0): array
    {
        if (self::$authFails) {
            throw new IgdbAuthException('Stubbed auth failure');
        }

        if (self::$searchFails) {
            throw new IgdbSearchException('Stubbed search failure');
        }

        return self::$results;
    }

    public function fetchSteamAppId(int $igdbId): ?int
    {
        if (self::$searchFails) {
            throw new IgdbSearchException('Stubbed search failure');
        }

        return self::$steamAppIds[$igdbId] ?? null;
    }

    public function fetchPlatforms(int $igdbId): array
    {
        if (self::$searchFails) {
            throw new IgdbSearchException('Stubbed search failure');
        }

        return self::$platforms[$igdbId] ?? [];
    }
}
