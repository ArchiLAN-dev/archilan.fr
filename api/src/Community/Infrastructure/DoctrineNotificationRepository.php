<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Domain\Notification;
use App\Community\Domain\NotificationRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineNotificationRepository implements NotificationRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Notification $notification): void
    {
        $this->entityManager->persist($notification);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?Notification
    {
        return $this->entityManager->getRepository(Notification::class)->find($id);
    }

    public function recentForRecipient(string $recipientId, int $limit): array
    {
        $qb = $this->entityManager->getRepository(Notification::class)->createQueryBuilder('n');
        $result = $qb
            ->where($qb->expr()->eq('n.recipientId', ':recipient'))
            ->setParameter('recipient', $recipientId)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        if (!is_array($result)) {
            return [];
        }

        return array_values(array_filter($result, static fn (mixed $n): bool => $n instanceof Notification));
    }

    public function countUnread(string $recipientId): int
    {
        $qb = $this->entityManager->getRepository(Notification::class)->createQueryBuilder('n');
        $count = $qb
            ->select('COUNT(n.id)')
            ->where($qb->expr()->eq('n.recipientId', ':recipient'))
            ->andWhere($qb->expr()->isNull('n.readAt'))
            ->setParameter('recipient', $recipientId)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function markAllRead(string $recipientId, \DateTimeImmutable $now): int
    {
        $qb = $this->entityManager->getRepository(Notification::class)->createQueryBuilder('n');
        $affected = $qb
            ->update(Notification::class, 'n')
            ->set('n.readAt', ':now')
            ->where($qb->expr()->eq('n.recipientId', ':recipient'))
            ->andWhere($qb->expr()->isNull('n.readAt'))
            ->setParameter('now', $now)
            ->setParameter('recipient', $recipientId)
            ->getQuery()
            ->execute();

        return is_int($affected) ? $affected : 0;
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
