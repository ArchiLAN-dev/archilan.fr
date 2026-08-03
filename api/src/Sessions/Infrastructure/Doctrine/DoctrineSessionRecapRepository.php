<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Doctrine;

use App\Sessions\Domain\Entity\SessionRecap;
use App\Sessions\Domain\Repository\SessionRecapRepositoryInterface;
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

    public function findExistingSessionIds(array $sessionIds): array
    {
        if ([] === $sessionIds) {
            return [];
        }

        // ORM finder rather than a query builder: the project bans DQL, and this stays one round trip.
        $recaps = $this->entityManager->getRepository(SessionRecap::class)->findBy(['sessionId' => $sessionIds]);

        return array_map(static fn (SessionRecap $recap): string => $recap->getSessionId(), $recaps);
    }

    public function save(SessionRecap $recap): void
    {
        $this->entityManager->persist($recap);
        $this->entityManager->flush();
    }
}
