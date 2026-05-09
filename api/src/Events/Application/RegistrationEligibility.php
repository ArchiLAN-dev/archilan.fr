<?php

declare(strict_types=1);

namespace App\Events\Application;

use App\Events\Domain\Event;
use App\Registrations\Application\RegistrationCounter;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RegistrationEligibility
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RegistrationCounter $registrationCounter,
    ) {
    }

    /**
     * Returns eligibility state for an authenticated user, or null if the event does not exist or is not publicly visible.
     *
     * @return array{eligible: bool, reason: string|null, opensAt: string|null, event: array{id: string, title: string, startsAt: string, endsAt: string, venue: string, capacity: int, confirmedRegistrations: int, registrationOpensAt: string, registrationClosesAt: string, isPublic: bool}}|null
     */
    public function check(string $eventId): ?array
    {
        $event = $this->entityManager->find(Event::class, $eventId);

        if (!$event instanceof Event || !$event->isVisiblePublicly()) {
            return null;
        }

        $now = new \DateTimeImmutable();
        $confirmedCount = $this->registrationCounter->countConfirmed($event->getId());
        $reason = $this->computeReason($event, $confirmedCount, $now);

        return [
            'eligible' => null === $reason,
            'reason' => $reason,
            'opensAt' => 'registration_not_open_yet' === $reason
                ? $event->getRegistrationOpensAt()->format(\DateTimeInterface::ATOM)
                : null,
            'event' => [
                'id' => $event->getId(),
                'title' => $event->getTitle(),
                'startsAt' => $event->getStartsAt()->format(\DateTimeInterface::ATOM),
                'endsAt' => $event->getEndsAt()->format(\DateTimeInterface::ATOM),
                'venue' => $event->getVenue(),
                'capacity' => $event->getCapacity(),
                'confirmedRegistrations' => $confirmedCount,
                'registrationOpensAt' => $event->getRegistrationOpensAt()->format(\DateTimeInterface::ATOM),
                'registrationClosesAt' => $event->getRegistrationClosesAt()->format(\DateTimeInterface::ATOM),
                'isPublic' => $event->isPublic(),
            ],
        ];
    }

    private function computeReason(Event $event, int $confirmedCount, \DateTimeImmutable $now): ?string
    {
        if (Event::STATUS_COMPLETED === $event->getStatus()) {
            return 'event_completed';
        }

        if (Event::STATUS_IN_PROGRESS === $event->getStatus()) {
            return 'event_in_progress';
        }

        if ($now < $event->getRegistrationOpensAt()) {
            return 'registration_not_open_yet';
        }

        if ($now > $event->getRegistrationClosesAt()) {
            return 'registration_closed';
        }

        if ($event->isAtCapacity($confirmedCount)) {
            return 'capacity_full';
        }

        if (!$event->isPublic()) {
            return 'private_event';
        }

        return null;
    }
}
