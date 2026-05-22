<?php

declare(strict_types=1);

namespace App\Membership\Application;

interface MembershipExpiryCheckQueryInterface
{
    /**
     * @return list<string> IDs of active memberships whose expires_at is in the past
     */
    public function findExpiredActiveIds(\DateTimeImmutable $now): array;

    /**
     * @return list<string> IDs of active memberships expiring within $daysLeft days that have not been reminded yet
     */
    public function findPendingReminderIds(\DateTimeImmutable $now, int $daysLeft): array;
}
