<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Doctrine;

use App\Sessions\Domain\SessionRecap;
use App\Sessions\Domain\SessionRecapRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSessionRecapRepository implements SessionRecapRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findBySessionId(string $sessionId): ?SessionRecap
    {
        return $this->entityManager->find(SessionRecap::class, $sessionId);
    }

    public function save(SessionRecap $recap): void
    {
        $this->entityManager->persist($recap);
        $this->entityManager->flush();
    }
}
