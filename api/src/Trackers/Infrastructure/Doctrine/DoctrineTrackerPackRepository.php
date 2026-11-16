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

    public function findByGameAndPackageUid(string $gameId, string $packageUid): ?TrackerPack
    {
        return $this->entityManager->getRepository(TrackerPack::class)->findOneBy(['gameId' => $gameId, 'packageUid' => $packageUid]);
    }

    public function save(TrackerPack $pack, TrackerPackVersion $version): void
    {
        $this->entityManager->persist($version);
        $this->entityManager->persist($pack);
        $this->entityManager->flush();
    }
}
