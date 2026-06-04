<?php

declare(strict_types=1);

namespace App\Registrations\Application;

interface PrivateAccessGrantedQueryInterface
{
    /**
     * Returns user IDs that were granted private access to the event.
     *
     * @return list<string>
     */
    public function findGrantedUserIds(string $eventId): array;

    /**
     * Returns number of granted private-access entries for a specific user and event.
     */
    public function countGrantedForUser(string $eventId, string $userId): int;
}
