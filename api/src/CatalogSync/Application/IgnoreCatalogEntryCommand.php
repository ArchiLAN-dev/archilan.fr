<?php

declare(strict_types=1);

namespace App\CatalogSync\Application;

use App\GameSelection\Domain\IgnoredCatalogEntry;
use Doctrine\ORM\EntityManagerInterface;

final readonly class IgnoreCatalogEntryCommand
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function execute(string $name): void
    {
        $existing = $this->entityManager->find(IgnoredCatalogEntry::class, $name);

        if (null !== $existing) {
            return;
        }

        $entry = new IgnoredCatalogEntry($name, new \DateTimeImmutable());
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }
}
