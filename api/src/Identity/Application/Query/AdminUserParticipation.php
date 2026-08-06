<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

use App\Membership\Application\Query\MembershipView;

/**
 * What a member has engaged in: their memberships and every event they signed up for (story 36.3).
 *
 * Both halves already existed as reads; neither was reachable per person from the admin side - the
 * memberships only through a site-wide list, the registrations only event by event.
 */
final readonly class AdminUserParticipation
{
    /**
     * @param list<MembershipView>                                                                                                                                                            $memberships
     * @param list<array{registrationId: string, eventSlug: string, eventTitle: string, eventStartDate: string|null, registrationStatus: string, slotCount: int, sessionStatus: string|null}> $registrations
     */
    public function __construct(
        public array $memberships,
        public array $registrations,
    ) {
    }
}
