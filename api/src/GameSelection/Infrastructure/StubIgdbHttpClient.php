<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

final class StubIgdbHttpClient implements IgdbHttpClientInterface
{
    public static bool $authFails = false;
    public static bool $searchFails = false;

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
}
