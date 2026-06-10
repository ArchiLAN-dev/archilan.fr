<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

interface AdminGameListQueryInterface
{
    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function find(int $page, int $perPage, string $search, ?string $availability, ?bool $yamlReady, ?bool $apworldReady = null, string $sort = 'name', string $dir = 'asc'): array;
}
