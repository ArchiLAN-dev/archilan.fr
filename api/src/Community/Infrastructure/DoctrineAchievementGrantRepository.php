<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Domain\AchievementGrant;
use App\Community\Domain\AchievementGrantRepositoryInterface;
use Doctrine\DBAL\ArrayParameterType;
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

    public function countByUsers(?array $userIds): array
    {
        if (null !== $userIds && [] === $userIds) {
            return [];
        }

        $qb = $this->entityManager->getConnection()->createQueryBuilder();
        $qb
            ->select('g.user_id AS uid', 'COUNT(g.id) AS cnt')
            ->from('community_achievement_grant', 'g')
            ->groupBy('g.user_id');

        if (null !== $userIds) {
            $qb->where($qb->expr()->in('g.user_id', ':ids'))->setParameter('ids', $userIds, ArrayParameterType::STRING);
        }

        $counts = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $uid = $row['uid'] ?? null;
            if (is_string($uid)) {
                $counts[$uid] = is_numeric($row['cnt'] ?? null) ? (int) $row['cnt'] : 0;
            }
        }

        return $counts;
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

    public function deleteByUserAndKey(string $userId, string $achievementKey): void
    {
        $grants = $this->entityManager->getRepository(AchievementGrant::class)
            ->findBy(['userId' => $userId, 'achievementKey' => $achievementKey]);

        if ([] === $grants) {
            return;
        }

        foreach ($grants as $grant) {
            $this->entityManager->remove($grant);
        }
        $this->entityManager->flush();
    }
}
