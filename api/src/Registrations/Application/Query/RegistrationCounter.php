<?php

declare(strict_types=1);

namespace App\Registrations\Application\Query;

final readonly class RegistrationCounter
{
    public function __construct(private RegistrationCounterQueryInterface $query)
    {
    }

    public function countConfirmed(string $eventId): int
    {
        return $this->query->countConfirmed($eventId);
    }
}
