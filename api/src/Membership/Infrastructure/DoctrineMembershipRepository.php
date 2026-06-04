<?php

declare(strict_types=1);

namespace App\Membership\Infrastructure;

use App\Membership\Domain\Membership;
use App\Membership\Domain\MembershipRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineMembershipRepository implements MembershipRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?Membership
    {
        return $this->entityManager->find(Membership::class, $id);
    }

    public function findActiveByUserId(string $userId): ?Membership
    {
        /* @var Membership|null */
        return $this->entityManager->getRepository(Membership::class)->findOneBy([
            'userId' => $userId,
            'status' => 'active',
        ]);
    }

    public function save(Membership $membership): void
    {
        $this->entityManager->persist($membership);
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
