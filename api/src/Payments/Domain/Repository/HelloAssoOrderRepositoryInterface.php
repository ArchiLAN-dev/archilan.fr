<?php

declare(strict_types=1);

namespace App\Payments\Domain\Repository;

use App\Payments\Domain\Entity\HelloAssoOrder;

interface HelloAssoOrderRepositoryInterface
{
    public function findByHelloAssoOrderId(int $orderId): ?HelloAssoOrder;

    /**
     * @return list<HelloAssoOrder>
     */
    public function findByFormSlugAndPayerEmail(string $formSlug, string $payerEmail, int $limit = 1): array;

    public function save(HelloAssoOrder $order): void;

    public function persist(HelloAssoOrder $order): void;

    public function flush(): void;
}
