<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

interface GameCatalogQueryInterface
{
    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function list(string $query = '', int $page = 1): array;

    /**
     * Full catalog (no pagination) for the client-driven Jeux page.
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $query = ''): array;
}
