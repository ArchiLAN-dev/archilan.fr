<?php

declare(strict_types=1);

namespace App\Registrations\Application\Command;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Entity\RegistrationPresenceWindow;
use App\Registrations\Domain\Repository\RegistrationPresenceWindowRepositoryInterface;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Registrations\Domain\Service\PresenceSchedule;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use Psr\Log\LoggerInterface;

/**
 * Story 52.2: when the member is at the event. The whole list is replaced at once, an empty list meaning "there the
 * whole event". The member edits their own seat until the event is over; an admin edits any seat of the event.
 */
final readonly class ChoosePresenceWindows
{
    public function __construct(
        private RegistrationRepositoryInterface $registrations,
        private EventRepositoryInterface $events,
        private RegistrationPresenceWindowRepositoryInterface $windows,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array{\DateTimeImmutable, \DateTimeImmutable}> $windows
     *
     * @throws NotFoundException   when the registration does not exist or is not the caller's
     * @throws ValidationException when the seat is cancelled, the event is over, or a window breaks a rule
     */
    public function asMember(string $registrationId, string $userId, array $windows): void
    {
        $registration = $this->registrations->findById($registrationId);
        if (null === $registration || $registration->getUserId() !== $userId) {
            throw new NotFoundException('Inscription introuvable.');
        }
        $event = $this->eventOf($registration);
        if (!$registration->isReserved() || Event::STATUS_COMPLETED === $event->getStatus()) {
            throw new ValidationException('Tes plages ne sont plus modifiables.', ['windows' => ['L\'inscription est annulée ou l\'événement est terminé.']], 'presence_closed');
        }

        $this->replace($registration, $event, $windows);
    }

    /**
     * @param list<array{\DateTimeImmutable, \DateTimeImmutable}> $windows
     *
     * @throws NotFoundException   when the registration is not one of the event's
     * @throws ValidationException when a window breaks a rule
     */
    public function asAdmin(string $eventId, string $registrationId, array $windows): void
    {
        $registration = $this->registrations->findById($registrationId);
        if (null === $registration || $registration->getEventId() !== $eventId) {
            throw new NotFoundException('Inscription introuvable.');
        }

        $this->replace($registration, $this->eventOf($registration), $windows);
    }

    private function eventOf(Registration $registration): Event
    {
        $event = $this->events->findById($registration->getEventId());
        if (null === $event) {
            throw new NotFoundException('Inscription introuvable.');
        }

        return $event;
    }

    /** @param list<array{\DateTimeImmutable, \DateTimeImmutable}> $windows */
    private function replace(Registration $registration, Event $event, array $windows): void
    {
        $problem = PresenceSchedule::problem($windows, $event->getStartsAt(), $event->getEndsAt());
        if (null !== $problem) {
            throw new ValidationException(PresenceSchedule::message($problem), ['windows' => [PresenceSchedule::message($problem)]], $problem);
        }

        $this->windows->replaceForRegistration($registration->getId(), array_map(
            static fn (array $window): RegistrationPresenceWindow => RegistrationPresenceWindow::create($registration->getId(), $window[0], $window[1]),
            $windows,
        ));
        $this->logger->info('registration.presence_windows', ['registrationId' => $registration->getId(), 'count' => count($windows)]);
    }
}
