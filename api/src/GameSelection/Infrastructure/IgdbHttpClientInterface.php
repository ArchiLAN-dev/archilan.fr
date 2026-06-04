<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

interface IgdbHttpClientInterface
{
    /**
     * @return list<array{igdbId: int, name: string, slug: string, summary: string|null, coverUrl: string|null}>
     */
    public function searchGames(string $query, int $limit = 10, int $offset = 0): array;
}
