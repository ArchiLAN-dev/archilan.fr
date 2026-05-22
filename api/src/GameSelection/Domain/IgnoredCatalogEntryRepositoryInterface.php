<?php

declare(strict_types=1);

namespace App\GameSelection\Domain;

interface IgnoredCatalogEntryRepositoryInterface
{
    public function findByName(string $name): ?IgnoredCatalogEntry;

    /**
     * @return list<IgnoredCatalogEntry>
     */
    public function findAll(): array;

    public function save(IgnoredCatalogEntry $entry): void;

    public function remove(IgnoredCatalogEntry $entry): void;
}
