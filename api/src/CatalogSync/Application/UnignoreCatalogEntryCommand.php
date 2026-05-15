<?php

declare(strict_types=1);

namespace App\CatalogSync\Application;

use App\GameSelection\Domain\IgnoredCatalogEntry;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UnignoreCatalogEntryCommand
{
    use EntityFinderTrait;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function execute(string $name): bool
    {
        try {
            $entry = $this->findOrFail(IgnoredCatalogEntry::class, $name);
        } catch (\RuntimeException) {
            return false;
        }

        $this->entityManager->remove($entry);
        $this->entityManager->flush();

        return true;
    }
}
