<?php

declare(strict_types=1);

namespace App\Registrations\Application\Query;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;

/** Story 47.1: the member's broadcast consent on their registration, and whether they may still change it. */
final readonly class BroadcastConsentQuery
{
    public function __construct(
        private RegistrationRepositoryInterface $registrationRepository,
        private EventRepositoryInterface $eventRepository,
    ) {
    }

    /** @return array{consent: bool, editable: bool}|null null when the registration is missing or not the caller's */
    public function forOwner(string $registrationId, string $userId): ?array
    {
        $registration = $this->registrationRepository->findById($registrationId);
        if (null === $registration || $registration->getUserId() !== $userId) {
            return null;
        }

        $event = $this->eventRepository->findById($registration->getEventId());
        if (null === $event) {
            return null;
        }

        return [
            'consent' => $registration->hasBroadcastConsent(),
            'editable' => $registration->isReserved() && Event::STATUS_COMPLETED !== $event->getStatus(),
        ];
    }
}
