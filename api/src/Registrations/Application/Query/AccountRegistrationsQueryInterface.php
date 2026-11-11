<?php

declare(strict_types=1);

namespace App\Registrations\Application\Query;

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
     *     eventStartsAt: string|null,
     *     eventEndsAt: string|null,
     *     eventStatus: string,
     * }>
     */
    public function findForUser(string $userId): array;
}
