<?php

declare(strict_types=1);

namespace App\Registrations\Application;

interface RegistrationCounterQueryInterface
{
    public function countConfirmed(string $eventId): int;
}
