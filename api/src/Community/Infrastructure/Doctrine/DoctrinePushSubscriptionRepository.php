<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Entity\PushSubscription;
use App\Community\Domain\Repository\PushSubscriptionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePushSubscriptionRepository implements PushSubscriptionRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findByEndpoint(string $endpoint): ?PushSubscription
    {
        return $this->entityManager->getRepository(PushSubscription::class)->findOneBy(['endpoint' => $endpoint]);
    }

    public function findByUserId(string $userId): array
    {
        return $this->entityManager->getRepository(PushSubscription::class)->findBy(['userId' => $userId], ['createdAt' => 'ASC']);
    }

    public function add(PushSubscription $subscription): void
    {
        $this->entityManager->persist($subscription);
    }

    public function remove(PushSubscription $subscription): void
    {
        $this->entityManager->remove($subscription);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
