<?php

declare(strict_types=1);

namespace App\Events\Application\Query;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Registrations\Application\Query\PrivateEventAccess;
use App\Registrations\Application\Query\RegistrationCounter;
use Psr\Clock\ClockInterface;

final readonly class RegistrationEligibility
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private RegistrationCounter $registrationCounter,
        private PrivateEventAccess $privateEventAccess,
        private UserRepositoryInterface $userRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Returns eligibility state for an authenticated user, or null if the event does not exist or is not publicly visible.
     *
     * @return array{eligible: bool, reason: string|null, opensAt: string|null, event: array{id: string, title: string, startsAt: string, endsAt: string, venue: string, capacity: int, confirmedRegistrations: int, registrationOpensAt: string, registrationClosesAt: string, isPublic: bool, hasPrivateAccessPassword: bool}}|null
     */
    public function check(string $eventId, string $userId): ?array
    {
        $event = $this->eventRepository->findById($eventId);
        if (null === $event) {
            return null;
        }

        if (!$event->isVisiblePublicly()) {
            return null;
        }

        $now = $this->clock->now();
        $confirmedCount = $this->registrationCounter->countConfirmed($event->getId());
        $reason = $this->computeReason($event, $confirmedCount, $now, $userId);

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
                // Story 4.23: the access-code form only shows when there is a code to give.
                'hasPrivateAccessPassword' => $event->hasPrivateAccessPassword(),
            ],
        ];
    }

    private function computeReason(Event $event, int $confirmedCount, \DateTimeImmutable $now, string $userId): ?string
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

        if (!$this->privateEventAccess->allows($event, $userId)) {
            return 'private_event';
        }

        // Story 4.22: the reservation refuses an unverified email; say so before the click, not after.
        $user = $this->userRepository->findById($userId);
        if (!$user instanceof User || !$user->isEmailVerified()) {
            return 'email_not_verified';
        }

        return null;
    }
}
