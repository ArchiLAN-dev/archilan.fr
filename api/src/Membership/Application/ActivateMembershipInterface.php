<?php

declare(strict_types=1);

namespace App\Membership\Application;

interface ActivateMembershipInterface
{
    public function activate(
        string $userId,
        \DateTimeImmutable $startedAt,
        string $source,
        ?string $helloassoOrderId = null,
        ?string $adminNote = null,
    ): void;
}
