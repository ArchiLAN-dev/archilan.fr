<?php

declare(strict_types=1);

namespace App\Registrations\Application;

interface AccountRegistrationsQueryInterface
{
    /**
     * @return list<array{
     *     registrationId: string,
     *     eventSlug: string,
     *     eventTitle: string,
     *     eventStartDate: string|null,
     *     registrationStatus: string,
     *     slotCount: int,
     *     sessionStatus: string|null,
     * }>
     */
    public function findForUser(string $userId): array;
}
