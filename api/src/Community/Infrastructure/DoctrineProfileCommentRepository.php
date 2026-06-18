<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Domain\ProfileComment;
use App\Community\Domain\ProfileCommentRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineProfileCommentRepository implements ProfileCommentRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?ProfileComment
    {
        return $this->entityManager->find(ProfileComment::class, $id);
    }

    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $qb = $this->entityManager->getRepository(ProfileComment::class)->createQueryBuilder('c');
        $result = $qb
            ->where($qb->expr()->in('c.id', ':ids'))
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        if (is_array($result)) {
            foreach ($result as $comment) {
                if ($comment instanceof ProfileComment) {
                    $byId[$comment->getId()] = $comment;
                }
            }
        }

        return $byId;
    }

    public function visibleForProfile(string $profileUserId, int $limit): array
    {
        $qb = $this->entityManager->getRepository(ProfileComment::class)->createQueryBuilder('c');
        $result = $qb
            ->where($qb->expr()->eq('c.profileUserId', ':profile'))
            ->andWhere($qb->expr()->isNull('c.hiddenAt'))
            ->setParameter('profile', $profileUserId)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        if (!is_array($result)) {
            return [];
        }

        return array_values(array_filter($result, static fn (mixed $c): bool => $c instanceof ProfileComment));
    }

    public function countByAuthorSince(string $authorId, \DateTimeImmutable $since): int
    {
        $qb = $this->entityManager->getRepository(ProfileComment::class)->createQueryBuilder('c');
        $count = $qb
            ->select('COUNT(c.id)')
            ->where($qb->expr()->eq('c.authorId', ':author'))
            ->andWhere($qb->expr()->gte('c.createdAt', ':since'))
            ->setParameter('author', $authorId)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function save(ProfileComment $comment): void
    {
        $this->entityManager->persist($comment);
        $this->entityManager->flush();
    }

    public function remove(ProfileComment $comment): void
    {
        $this->entityManager->remove($comment);
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
