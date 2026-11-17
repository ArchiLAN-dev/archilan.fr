<?php

declare(strict_types=1);

namespace App\Trackers\Infrastructure\Doctrine;

use App\Trackers\Domain\Entity\TrackerPack;
use App\Trackers\Domain\Entity\TrackerPackCompatibility;
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

    public function findEnabled(): array
    {
        return $this->entityManager->getRepository(TrackerPack::class)->findBy(['enabled' => true], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function findVersion(string $versionId): ?TrackerPackVersion
    {
        return $this->entityManager->find(TrackerPackVersion::class, $versionId);
    }

    public function findVersions(string $packId): array
    {
        return $this->entityManager->getRepository(TrackerPackVersion::class)->findBy(['packId' => $packId], ['importedAt' => 'DESC', 'id' => 'DESC']);
    }

    public function findCompatibility(string $versionId, string $variantUid): ?TrackerPackCompatibility
    {
        return $this->entityManager->find(TrackerPackCompatibility::class, ['versionId' => $versionId, 'variantUid' => $variantUid]);
    }

    public function saveCompatibility(TrackerPackCompatibility $compatibility): void
    {
        $this->entityManager->persist($compatibility);
        $this->entityManager->flush();
    }

    public function save(TrackerPack $pack, TrackerPackVersion $version, array $expired = []): void
    {
        $this->entityManager->persist($version);
        $this->entityManager->persist($pack);
        foreach ($expired as $old) {
            foreach ($this->entityManager->getRepository(TrackerPackCompatibility::class)->findBy(['versionId' => $old->getId()]) as $try) {
                $this->entityManager->remove($try);
            }
            $this->entityManager->remove($old);
        }
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
