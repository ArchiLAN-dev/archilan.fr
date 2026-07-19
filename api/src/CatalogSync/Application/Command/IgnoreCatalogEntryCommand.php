<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Command;

use App\GameSelection\Domain\Entity\IgnoredCatalogEntry;
use App\GameSelection\Domain\Repository\IgnoredCatalogEntryRepositoryInterface;
use Psr\Clock\ClockInterface;

final readonly class IgnoreCatalogEntryCommand
{
    public function __construct(
        private IgnoredCatalogEntryRepositoryInterface $ignoredEntryRepository,
        private ClockInterface $clock,
    ) {
    }

    public function execute(string $name): void
    {
        $existing = $this->ignoredEntryRepository->findByName($name);

        if (null !== $existing) {
            return;
        }

        $entry = new IgnoredCatalogEntry($name, $this->clock->now());
        $this->ignoredEntryRepository->save($entry);
    }
}
