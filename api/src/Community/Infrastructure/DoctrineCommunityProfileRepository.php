<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Domain\CommunityProfile;
use App\Community\Domain\CommunityProfileRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineCommunityProfileRepository implements CommunityProfileRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findByUserId(string $userId): ?CommunityProfile
    {
        return $this->entityManager->getRepository(CommunityProfile::class)->findOneBy(['userId' => $userId]);
    }

    public function findNeedingAvatarRefresh(\DateTimeImmutable $staleBefore, int $limit): array
    {
        $qb = $this->entityManager->getRepository(CommunityProfile::class)->createQueryBuilder('p');

        $result = $qb
            ->where($qb->expr()->orX(
                $qb->expr()->isNull('p.avatarResolvedAt'),
                $qb->expr()->lt('p.avatarResolvedAt', ':staleBefore'),
            ))
            ->setParameter('staleBefore', $staleBefore)
            ->orderBy('p.avatarResolvedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        if (!is_array($result)) {
            return [];
        }

        return array_values(array_filter($result, static fn (mixed $p): bool => $p instanceof CommunityProfile));
    }

    public function save(CommunityProfile $profile): void
    {
        $this->entityManager->persist($profile);
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
