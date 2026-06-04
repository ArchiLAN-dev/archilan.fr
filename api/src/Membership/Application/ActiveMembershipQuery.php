<?php

declare(strict_types=1);

namespace App\Membership\Application;

final readonly class ActiveMembershipQuery implements ActiveMembershipQueryInterface
{
    public function __construct(private ActiveMembershipQueryInterface $query)
    {
    }

    public function hasActiveMembership(string $userId): bool
    {
        return $this->query->hasActiveMembership($userId);
    }
}
