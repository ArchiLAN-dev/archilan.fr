<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure\Doctrine;

use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Enum\ApworldIncidentStatus;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Repository\ApworldIncidentRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineApworldIncidentRepository implements ApworldIncidentRepositoryInterface
{
    private const array ACTIVE_STATUSES = [ApworldIncidentStatus::Open, ApworldIncidentStatus::Acknowledged];
    private const array CLOSED_STATUSES = [ApworldIncidentStatus::Resolved, ApworldIncidentStatus::Ignored];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(ApworldIncident $incident): void
    {
        $this->entityManager->persist($incident);
    }

    public function findById(string $id): ?ApworldIncident
    {
        return $this->entityManager->find(ApworldIncident::class, $id);
    }

    public function findActive(string $gameId, string $apworldHash, ApworldIncidentType $type): ?ApworldIncident
    {
        return $this->entityManager->getRepository(ApworldIncident::class)->findOneBy([
            'gameId' => $gameId,
            'apworldHash' => $apworldHash,
            'type' => $type,
            'status' => self::ACTIVE_STATUSES,
        ]);
    }

    public function findLatestClosed(string $gameId, string $apworldHash, ApworldIncidentType $type): ?ApworldIncident
    {
        return $this->entityManager->getRepository(ApworldIncident::class)->findOneBy(
            [
                'gameId' => $gameId,
                'apworldHash' => $apworldHash,
                'type' => $type,
                'status' => self::CLOSED_STATUSES,
            ],
            ['closedAt' => 'DESC'],
        );
    }

    public function findAllActive(): array
    {
        return $this->entityManager->getRepository(ApworldIncident::class)->findBy(
            ['status' => self::ACTIVE_STATUSES],
            ['openedAt' => 'ASC'],
        );
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
