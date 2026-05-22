<?php

declare(strict_types=1);

namespace App\CatalogSync\Application;

use App\GameSelection\Domain\IgnoredCatalogEntry;
use App\GameSelection\Domain\IgnoredCatalogEntryRepositoryInterface;

final readonly class IgnoreCatalogEntryCommand
{
    public function __construct(private IgnoredCatalogEntryRepositoryInterface $ignoredEntryRepository)
    {
    }

    public function execute(string $name): void
    {
        $existing = $this->ignoredEntryRepository->findByName($name);

        if (null !== $existing) {
            return;
        }

        $entry = new IgnoredCatalogEntry($name, new \DateTimeImmutable());
        $this->ignoredEntryRepository->save($entry);
    }
}
