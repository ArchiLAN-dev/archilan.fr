<?php

declare(strict_types=1);

namespace App\Registrations\Domain\Repository;

use App\Registrations\Domain\Entity\RegistrationPresenceWindow;

/** Story 52.2. */
interface RegistrationPresenceWindowRepositoryInterface
{
    /** @return list<RegistrationPresenceWindow> ordered by arrival */
    public function findByRegistration(string $registrationId): array;

    /**
     * Replaces every window of the registration, in one transaction.
     *
     * @param list<RegistrationPresenceWindow> $windows
     */
    public function replaceForRegistration(string $registrationId, array $windows): void;
}
