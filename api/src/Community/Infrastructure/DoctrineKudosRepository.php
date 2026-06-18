<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Domain\Kudos;
use App\Community\Domain\KudosRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineKudosRepository implements KudosRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $actorId, string $targetType, string $targetId): ?Kudos
    {
        return $this->entityManager->getRepository(Kudos::class)
            ->findOneBy(['actorId' => $actorId, 'targetType' => $targetType, 'targetId' => $targetId]);
    }

    public function count(string $targetType, string $targetId): int
    {
        $qb = $this->entityManager->getRepository(Kudos::class)->createQueryBuilder('k');
        $count = $qb
            ->select('COUNT(k.id)')
            ->where($qb->expr()->eq('k.targetType', ':type'))
            ->andWhere($qb->expr()->eq('k.targetId', ':id'))
            ->setParameter('type', $targetType)
            ->setParameter('id', $targetId)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function countsFor(string $targetType, array $targetIds): array
    {
        if ([] === $targetIds) {
            return [];
        }

        $qb = $this->entityManager->getRepository(Kudos::class)->createQueryBuilder('k');
        $rows = $qb
            ->select('k.targetId AS target_id', 'COUNT(k.id) AS total')
            ->where($qb->expr()->eq('k.targetType', ':type'))
            ->andWhere($qb->expr()->in('k.targetId', ':ids'))
            ->setParameter('type', $targetType)
            ->setParameter('ids', $targetIds)
            ->groupBy('k.targetId')
            ->getQuery()
            ->getResult();

        $counts = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && is_string($row['target_id'] ?? null)) {
                    $counts[$row['target_id']] = is_numeric($row['total'] ?? null) ? (int) $row['total'] : 0;
                }
            }
        }

        return $counts;
    }

    public function givenBy(string $actorId, string $targetType, array $targetIds): array
    {
        if ([] === $targetIds) {
            return [];
        }

        $qb = $this->entityManager->getRepository(Kudos::class)->createQueryBuilder('k');
        $rows = $qb
            ->select('k.targetId AS target_id')
            ->where($qb->expr()->eq('k.actorId', ':actor'))
            ->andWhere($qb->expr()->eq('k.targetType', ':type'))
            ->andWhere($qb->expr()->in('k.targetId', ':ids'))
            ->setParameter('actor', $actorId)
            ->setParameter('type', $targetType)
            ->setParameter('ids', $targetIds)
            ->getQuery()
            ->getResult();

        $given = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && is_string($row['target_id'] ?? null)) {
                    $given[] = $row['target_id'];
                }
            }
        }

        return $given;
    }

    public function save(Kudos $kudos): void
    {
        $this->entityManager->persist($kudos);
        $this->entityManager->flush();
    }

    public function remove(Kudos $kudos): void
    {
        $this->entityManager->remove($kudos);
        $this->entityManager->flush();
    }
}
