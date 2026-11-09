<?php

declare(strict_types=1);

namespace App\Registrations\Application\Query;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Entity\RegistrationPresenceWindow;
use App\Registrations\Domain\Repository\RegistrationPresenceWindowRepositoryInterface;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;

/**
 * Story 52.2: a registration's presence windows, with the event's dates (the bounds of any window) and whether the
 * member may still change them.
 *
 * @phpstan-type PresenceWindowView array{startsAt: string, endsAt: string}
 * @phpstan-type PresenceWindows array{windows: list<PresenceWindowView>, editable: bool, eventStartsAt: string, eventEndsAt: string}
 */
final readonly class PresenceWindowsQuery
{
    public function __construct(
        private RegistrationRepositoryInterface $registrations,
        private EventRepositoryInterface $events,
        private RegistrationPresenceWindowRepositoryInterface $windows,
    ) {
    }

    /** @return PresenceWindows|null null when the registration is missing or not the caller's */
    public function forOwner(string $registrationId, string $userId): ?array
    {
        $registration = $this->registrations->findById($registrationId);
        if (null === $registration || $registration->getUserId() !== $userId) {
            return null;
        }

        return $this->view($registration);
    }

    /** @return PresenceWindows|null null when the registration is not one of the event's */
    public function forAdmin(string $eventId, string $registrationId): ?array
    {
        $registration = $this->registrations->findById($registrationId);
        if (null === $registration || $registration->getEventId() !== $eventId) {
            return null;
        }

        return $this->view($registration);
    }

    /** @return PresenceWindows|null */
    private function view(Registration $registration): ?array
    {
        $event = $this->events->findById($registration->getEventId());
        if (null === $event) {
            return null;
        }

        return [
            'windows' => array_map(
                static fn (RegistrationPresenceWindow $window): array => [
                    'startsAt' => $window->getStartsAt()->format(\DateTimeInterface::ATOM),
                    'endsAt' => $window->getEndsAt()->format(\DateTimeInterface::ATOM),
                ],
                $this->windows->findByRegistration($registration->getId()),
            ),
            'editable' => $registration->isReserved() && Event::STATUS_COMPLETED !== $event->getStatus(),
            'eventStartsAt' => $event->getStartsAt()->format(\DateTimeInterface::ATOM),
            'eventEndsAt' => $event->getEndsAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
