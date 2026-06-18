<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Domain\AchievementGrant;
use App\Community\Domain\AchievementGrantRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAchievementGrantRepository implements AchievementGrantRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function grantedKeys(string $userId): array
    {
        return array_map(
            static fn (AchievementGrant $grant): string => $grant->getAchievementKey(),
            $this->findByUser($userId),
        );
    }

    public function findByUser(string $userId): array
    {
        /* @var list<AchievementGrant> */
        return $this->entityManager->getRepository(AchievementGrant::class)->findBy(['userId' => $userId]);
    }

    public function ownerOf(string $grantId): ?string
    {
        $grant = $this->entityManager->getRepository(AchievementGrant::class)->find($grantId);

        return $grant instanceof AchievementGrant ? $grant->getUserId() : null;
    }

    public function save(AchievementGrant $grant): void
    {
        $this->entityManager->persist($grant);
        $this->entityManager->flush();
    }
}
