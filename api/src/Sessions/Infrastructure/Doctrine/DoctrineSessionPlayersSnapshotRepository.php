<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Doctrine;

use App\Sessions\Domain\Entity\SessionPlayersSnapshot;
use App\Sessions\Domain\Repository\SessionPlayersSnapshotRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSessionPlayersSnapshotRepository implements SessionPlayersSnapshotRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findBySessionId(string $sessionId): ?SessionPlayersSnapshot
    {
        return $this->entityManager->find(SessionPlayersSnapshot::class, $sessionId);
    }

    public function save(SessionPlayersSnapshot $snapshot): void
    {
        $this->entityManager->persist($snapshot);
        $this->entityManager->flush();
    }
}
