<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Domain\ActivityEntry;
use App\Community\Domain\ActivityEntryRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineActivityEntryRepository implements ActivityEntryRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function exists(string $actorId, string $type, string $subjectRef): bool
    {
        return null !== $this->entityManager->getRepository(ActivityEntry::class)
            ->findOneBy(['actorId' => $actorId, 'type' => $type, 'subjectRef' => $subjectRef]);
    }

    public function save(ActivityEntry $entry): void
    {
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    public function recentForActors(array $actorIds, int $limit, ?\DateTimeImmutable $before = null): array
    {
        if ([] === $actorIds) {
            return [];
        }

        $qb = $this->entityManager->getRepository(ActivityEntry::class)->createQueryBuilder('a');
        $qb
            ->where($qb->expr()->in('a.actorId', ':actors'))
            ->setParameter('actors', $actorIds)
            ->orderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $before) {
            $qb->andWhere($qb->expr()->lt('a.occurredAt', ':before'))->setParameter('before', $before);
        }

        $result = $qb->getQuery()->getResult();

        if (!is_array($result)) {
            return [];
        }

        return array_values(array_filter($result, static fn (mixed $e): bool => $e instanceof ActivityEntry));
    }
}
