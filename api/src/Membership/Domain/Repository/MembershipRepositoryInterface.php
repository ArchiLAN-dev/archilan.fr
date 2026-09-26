<?php

declare(strict_types=1);

namespace App\Membership\Domain\Repository;

use App\Membership\Domain\Entity\Membership;

interface MembershipRepositoryInterface
{
    public function findById(string $id): ?Membership;

    public function findActiveByUserId(string $userId): ?Membership;

    /**
     * The membership, active or expired, that carries this HelloAsso order (story 22.7).
     */
    public function findByHelloassoOrderId(string $helloassoOrderId): ?Membership;

    public function save(Membership $membership): void;

    public function flush(): void;
}
