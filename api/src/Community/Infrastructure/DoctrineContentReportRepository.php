<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Domain\ContentReport;
use App\Community\Domain\ContentReportRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineContentReportRepository implements ContentReportRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function exists(string $reporterId, string $targetType, string $targetId): bool
    {
        return null !== $this->entityManager->getRepository(ContentReport::class)
            ->findOneBy(['reporterId' => $reporterId, 'targetType' => $targetType, 'targetId' => $targetId]);
    }

    public function findById(string $id): ?ContentReport
    {
        return $this->entityManager->find(ContentReport::class, $id);
    }

    public function pending(int $limit): array
    {
        $qb = $this->entityManager->getRepository(ContentReport::class)->createQueryBuilder('r');
        $result = $qb
            ->where($qb->expr()->isNull('r.resolvedAt'))
            ->orderBy('r.createdAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        if (!is_array($result)) {
            return [];
        }

        return array_values(array_filter($result, static fn (mixed $r): bool => $r instanceof ContentReport));
    }

    public function countPending(): int
    {
        $qb = $this->entityManager->getRepository(ContentReport::class)->createQueryBuilder('r');
        $count = $qb
            ->select('COUNT(r.id)')
            ->where($qb->expr()->isNull('r.resolvedAt'))
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function save(ContentReport $report): void
    {
        $this->entityManager->persist($report);
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
