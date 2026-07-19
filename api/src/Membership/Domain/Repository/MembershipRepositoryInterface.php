<?php

declare(strict_types=1);

namespace App\Membership\Domain\Repository;

use App\Membership\Domain\Entity\Membership;

interface MembershipRepositoryInterface
{
    public function findById(string $id): ?Membership;

    public function findActiveByUserId(string $userId): ?Membership;

    public function save(Membership $membership): void;

    public function flush(): void;
}
