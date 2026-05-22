<?php

declare(strict_types=1);

namespace App\Sessions\Application;

interface ActiveRegistrationQueryInterface
{
    public function hasActiveForEvent(string $userId, string $eventId): bool;
}
