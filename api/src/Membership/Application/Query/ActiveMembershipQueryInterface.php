<?php

declare(strict_types=1);

namespace App\Membership\Application\Query;

interface ActiveMembershipQueryInterface
{
    public function hasActiveMembership(string $userId): bool;

    /**
     * Batch variant of hasActiveMembership: of the given users, those with an active (non-expired)
     * membership. A user not in the returned list has no active membership. Avoids an N+1 when
     * resolving the member badge for a list of users.
     *
     * @param list<string> $userIds
     *
     * @return list<string>
     */
    public function activeMemberIds(array $userIds): array;
}
