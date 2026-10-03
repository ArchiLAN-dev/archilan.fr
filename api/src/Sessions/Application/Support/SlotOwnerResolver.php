<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Domain\Entity\SessionSlot;

/**
 * The member who owns a session slot (story 41.4): `registrationId` holds the member id on a personal run's
 * session (LaunchPersonalRunJobHandler) and the registration id on an event session (as SessionSlotOwnersQuery
 * reads it). Co-players are not owners.
 */
final readonly class SlotOwnerResolver
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private RegistrationRepositoryInterface $registrations,
    ) {
    }

    public function ownerOf(SessionSlot $slot): ?string
    {
        if ($this->runs->findBySessionId($slot->getSessionId()) instanceof Run) {
            return $slot->getRegistrationId();
        }

        $registration = $this->registrations->findById($slot->getRegistrationId());

        return $registration instanceof Registration ? $registration->getUserId() : null;
    }
}
