<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Membership\Application\Query\AdminMembershipListQuery;
use App\Registrations\Application\Query\AccountRegistrationsQuery;

/**
 * The membership + registrations panel of the admin user sheet (story 36.3).
 *
 * Deliberately nothing but composition. Both reads already existed and are already filterable by user -
 * `AdminMembershipListQuery` takes a `userId`, `AccountRegistrationsQuery` is built around one - they
 * were simply never assembled anywhere an admin could look. Adding a DBAL query here would duplicate
 * SQL that is already written and tested.
 */
final readonly class AdminUserParticipationQuery
{
    /**
     * A member has years of history at worst, not hundreds of rows. Bounded anyway rather than claiming
     * an exhaustiveness the pagination cannot guarantee.
     */
    private const int MEMBERSHIP_LIMIT = 50;

    public function __construct(
        private UserRepositoryInterface $users,
        private AdminMembershipListQuery $memberships,
        private AccountRegistrationsQuery $registrations,
    ) {
    }

    /**
     * Null when no such account exists, so the controller answers 404 with a single Application call
     * (AC-P4).
     */
    public function forUser(string $userId): ?AdminUserParticipation
    {
        if (null === $this->users->findById($userId)) {
            return null;
        }

        $memberships = $this->memberships->search(1, self::MEMBERSHIP_LIMIT, null, null, $userId);

        return new AdminUserParticipation(
            $memberships['data'],
            $this->registrations->findForUser($userId),
        );
    }
}
