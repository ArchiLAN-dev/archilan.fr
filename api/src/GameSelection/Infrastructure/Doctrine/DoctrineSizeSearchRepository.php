<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure\Doctrine;

use App\GameSelection\Domain\Entity\SizeSearch;
use App\GameSelection\Domain\Repository\SizeSearchRepositoryInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSizeSearchRepository implements SizeSearchRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?SizeSearch
    {
        return $this->entityManager->find(SizeSearch::class, $id);
    }

    public function save(SizeSearch $search): void
    {
        $this->entityManager->persist($search);
        $this->entityManager->flush();
    }

    public function withLock(string $id, \Closure $work): void
    {
        $this->entityManager->wrapInTransaction(function () use ($id, $work): void {
            $search = $this->entityManager->find(SizeSearch::class, $id);
            if (null === $search) {
                return;
            }
            // The copy in memory may predate a restart: read it again, under the lock.
            $this->entityManager->refresh($search, LockMode::PESSIMISTIC_WRITE);
            $work($search);
        });
    }
}
