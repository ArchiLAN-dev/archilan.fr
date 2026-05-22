<?php

declare(strict_types=1);

namespace App\Sessions\Application;

interface PlayerConnectionQueryInterface
{
    public function findLatestSessionIdByRegistrationId(string $registrationId): ?string;
}
