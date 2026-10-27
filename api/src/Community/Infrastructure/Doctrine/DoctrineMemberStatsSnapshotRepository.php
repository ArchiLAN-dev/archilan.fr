<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Entity\MemberStatsSnapshot;
use App\Community\Domain\Repository\MemberStatsSnapshotRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineMemberStatsSnapshotRepository implements MemberStatsSnapshotRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findByUserId(string $userId): ?MemberStatsSnapshot
    {
        return $this->entityManager->find(MemberStatsSnapshot::class, $userId);
    }

    public function save(MemberStatsSnapshot $snapshot): void
    {
        $this->entityManager->persist($snapshot);
        $this->entityManager->flush();
    }
}
