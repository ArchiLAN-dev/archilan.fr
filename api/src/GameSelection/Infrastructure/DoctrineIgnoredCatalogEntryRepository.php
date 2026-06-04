<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

use App\GameSelection\Domain\IgnoredCatalogEntry;
use App\GameSelection\Domain\IgnoredCatalogEntryRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineIgnoredCatalogEntryRepository implements IgnoredCatalogEntryRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findByName(string $name): ?IgnoredCatalogEntry
    {
        return $this->entityManager->find(IgnoredCatalogEntry::class, $name);
    }

    public function findAll(): array
    {
        /* @var list<IgnoredCatalogEntry> */
        return $this->entityManager->getRepository(IgnoredCatalogEntry::class)->findAll();
    }

    public function save(IgnoredCatalogEntry $entry): void
    {
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    public function remove(IgnoredCatalogEntry $entry): void
    {
        $this->entityManager->remove($entry);
        $this->entityManager->flush();
    }
}
