<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

final readonly class PublicGameCatalog
{
    public function __construct(private GameCatalogQueryInterface $query)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function list(string $query = '', int $page = 1): array
    {
        return $this->query->list($query, $page);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(string $query = ''): array
    {
        return $this->query->all($query);
    }
}
