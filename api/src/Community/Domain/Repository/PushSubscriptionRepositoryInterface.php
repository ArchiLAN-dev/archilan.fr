<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\PushSubscription;

interface PushSubscriptionRepositoryInterface
{
    public function findByEndpoint(string $endpoint): ?PushSubscription;

    /**
     * @return list<PushSubscription>
     */
    public function findByUserId(string $userId): array;

    public function add(PushSubscription $subscription): void;

    public function remove(PushSubscription $subscription): void;

    public function flush(): void;
}
