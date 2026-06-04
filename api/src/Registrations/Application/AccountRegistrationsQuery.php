<?php

declare(strict_types=1);

namespace App\Registrations\Application;

final readonly class AccountRegistrationsQuery
{
    public function __construct(private AccountRegistrationsQueryInterface $query)
    {
    }

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
    public function findForUser(string $userId): array
    {
        return $this->query->findForUser($userId);
    }
}
