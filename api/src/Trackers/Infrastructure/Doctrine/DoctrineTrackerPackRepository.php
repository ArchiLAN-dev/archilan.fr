<?php

declare(strict_types=1);

namespace App\Trackers\Infrastructure\Doctrine;

use App\Trackers\Domain\Entity\TrackerPack;
use App\Trackers\Domain\Entity\TrackerPackVersion;
use App\Trackers\Domain\Repository\TrackerPackRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineTrackerPackRepository implements TrackerPackRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $id): ?TrackerPack
    {
        return $this->entityManager->find(TrackerPack::class, $id);
    }

    public function findByGame(string $gameId): array
    {
        return $this->entityManager->getRepository(TrackerPack::class)->findBy(['gameId' => $gameId], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function findByGameAndPackageUid(string $gameId, string $packageUid): ?TrackerPack
    {
        return $this->entityManager->getRepository(TrackerPack::class)->findOneBy(['gameId' => $gameId, 'packageUid' => $packageUid]);
    }

    public function findVersion(string $versionId): ?TrackerPackVersion
    {
        return $this->entityManager->find(TrackerPackVersion::class, $versionId);
    }

    public function save(TrackerPack $pack, TrackerPackVersion $version): void
    {
        $this->entityManager->persist($version);
        $this->entityManager->persist($pack);
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
