<?php

declare(strict_types=1);

namespace App\Payments\Infrastructure;

use App\Payments\Domain\HelloAssoOrder;
use App\Payments\Domain\HelloAssoOrderRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineHelloAssoOrderRepository implements HelloAssoOrderRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findByHelloAssoOrderId(int $orderId): ?HelloAssoOrder
    {
        /* @var HelloAssoOrder|null */
        return $this->entityManager->getRepository(HelloAssoOrder::class)->findOneBy(['helloassoOrderId' => $orderId]);
    }

    public function findByFormSlugAndPayerEmail(string $formSlug, string $payerEmail, int $limit = 1): array
    {
        /* @var list<HelloAssoOrder> */
        return $this->entityManager->getRepository(HelloAssoOrder::class)->findBy(
            ['formSlug' => $formSlug, 'payerEmail' => $payerEmail],
            ['syncedAt' => 'DESC'],
            $limit,
        );
    }

    public function save(HelloAssoOrder $order): void
    {
        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }

    public function persist(HelloAssoOrder $order): void
    {
        $this->entityManager->persist($order);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
