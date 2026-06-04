<?php

declare(strict_types=1);

namespace App\Membership\Application;

interface AccountMembershipQueryInterface
{
    /**
     * @return array{status: 'active'|'expired'|'none', expiresAt: string|null, startedAt: string|null}
     */
    public function queryForUser(string $userId): array;

    /**
     * @return list<array{id: string, status: string, startedAt: string|null, expiresAt: string|null, source: string}>
     */
    public function queryHistoryForUser(string $userId): array;
}
