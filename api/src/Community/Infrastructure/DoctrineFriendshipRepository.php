<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Domain\Friendship;
use App\Community\Domain\FriendshipRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineFriendshipRepository implements FriendshipRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?Friendship
    {
        return $this->entityManager->find(Friendship::class, $id);
    }

    public function findBetween(string $a, string $b): ?Friendship
    {
        return $this->entityManager->getRepository(Friendship::class)
            ->findOneBy(['pairKey' => Friendship::pairKey($a, $b)]);
    }

    public function areFriends(string $a, string $b): bool
    {
        $friendship = $this->findBetween($a, $b);

        return $friendship instanceof Friendship && $friendship->isAccepted();
    }

    public function findAccepted(string $userId): array
    {
        return $this->queryInvolving($userId, Friendship::ACCEPTED);
    }

    public function findIncomingPending(string $userId): array
    {
        /* @var list<Friendship> */
        return $this->entityManager->getRepository(Friendship::class)
            ->findBy(['addresseeId' => $userId, 'status' => Friendship::PENDING], ['createdAt' => 'DESC']);
    }

    public function findOutgoingPending(string $userId): array
    {
        /* @var list<Friendship> */
        return $this->entityManager->getRepository(Friendship::class)
            ->findBy(['requesterId' => $userId, 'status' => Friendship::PENDING], ['createdAt' => 'DESC']);
    }

    public function save(Friendship $friendship): void
    {
        $this->entityManager->persist($friendship);
        $this->entityManager->flush();
    }

    public function remove(Friendship $friendship): void
    {
        $this->entityManager->remove($friendship);
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    /**
     * @return list<Friendship>
     */
    private function queryInvolving(string $userId, string $status): array
    {
        $qb = $this->entityManager->getRepository(Friendship::class)->createQueryBuilder('f');
        $result = $qb
            ->where($qb->expr()->eq('f.status', ':status'))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('f.requesterId', ':userId'),
                $qb->expr()->eq('f.addresseeId', ':userId'),
            ))
            ->setParameter('status', $status)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        if (!is_array($result)) {
            return [];
        }

        return array_values(array_filter($result, static fn (mixed $f): bool => $f instanceof Friendship));
    }
}
