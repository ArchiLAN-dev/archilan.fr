<?php

declare(strict_types=1);

namespace App\Membership\Application;

interface ActiveMembershipQueryInterface
{
    public function hasActiveMembership(string $userId): bool;
}
