<?php

declare(strict_types=1);

namespace App\Membership\Application;

interface ExpireMembershipInterface
{
    public function expire(string $membershipId): void;
}
