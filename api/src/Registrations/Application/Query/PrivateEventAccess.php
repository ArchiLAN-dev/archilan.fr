<?php

declare(strict_types=1);

namespace App\Registrations\Application\Query;

use App\Events\Domain\Entity\Event;
use App\Membership\Application\Query\ActiveMembershipQueryInterface;

/**
 * Story 4.21: who may register to an event. A public event is open to every member; a private one to an active
 * adherent, or to a member who gave its access code. Eligibility and reservation both ask here, so they never drift.
 */
final readonly class PrivateEventAccess
{
    public function __construct(
        private ActiveMembershipQueryInterface $memberships,
        private PrivateAccessGrantedQueryInterface $grants,
    ) {
    }

    public function allows(Event $event, string $userId): bool
    {
        return $event->isPublic()
            || $this->memberships->hasActiveMembership($userId)
            || $this->grants->countGrantedForUser($event->getId(), $userId) > 0;
    }
}
