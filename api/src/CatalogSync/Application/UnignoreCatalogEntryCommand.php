<?php

declare(strict_types=1);

namespace App\CatalogSync\Application;

use App\GameSelection\Domain\IgnoredCatalogEntryRepositoryInterface;

final readonly class UnignoreCatalogEntryCommand
{
    public function __construct(private IgnoredCatalogEntryRepositoryInterface $ignoredEntryRepository)
    {
    }

    public function execute(string $name): bool
    {
        $entry = $this->ignoredEntryRepository->findByName($name);

        if (null === $entry) {
            return false;
        }

        $this->ignoredEntryRepository->remove($entry);

        return true;
    }
}
