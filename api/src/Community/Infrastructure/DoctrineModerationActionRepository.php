<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Domain\ModerationAction;
use App\Community\Domain\ModerationActionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineModerationActionRepository implements ModerationActionRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(ModerationAction $action): void
    {
        $this->entityManager->persist($action);
        $this->entityManager->flush();
    }

    public function forTarget(string $targetUserId, int $limit): array
    {
        $qb = $this->entityManager->getRepository(ModerationAction::class)->createQueryBuilder('a');
        $result = $qb
            ->where($qb->expr()->eq('a.targetUserId', ':target'))
            ->setParameter('target', $targetUserId)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        if (!is_array($result)) {
            return [];
        }

        return array_values(array_filter($result, static fn (mixed $a): bool => $a instanceof ModerationAction));
    }

    public function beginTransaction(): void
    {
        $this->entityManager->getConnection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->entityManager->getConnection()->commit();
    }

    public function rollBack(): void
    {
        $this->entityManager->getConnection()->rollBack();
    }
}
