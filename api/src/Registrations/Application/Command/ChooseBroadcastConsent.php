<?php

declare(strict_types=1);

namespace App\Registrations\Application\Command;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Story 47.1: the member says whether the ArchiLAN broadcast may show their Twitch stream during the event. Only on
 * their own confirmed seat, and until the event is over; an admin never decides it for them.
 */
final readonly class ChooseBroadcastConsent
{
    public function __construct(
        private RegistrationRepositoryInterface $registrationRepository,
        private EventRepositoryInterface $eventRepository,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws NotFoundException   when the registration does not exist or is not the caller's
     * @throws ValidationException when the seat is cancelled or the event is over
     */
    public function choose(string $registrationId, string $userId, bool $consent): void
    {
        $registration = $this->registrationRepository->findById($registrationId);

        // Someone else's registration answers like a missing one, as the other member routes do.
        if (null === $registration || $registration->getUserId() !== $userId) {
            throw new NotFoundException('Inscription introuvable.');
        }

        $event = $this->eventRepository->findById($registration->getEventId());
        if (null === $event) {
            throw new NotFoundException('Inscription introuvable.');
        }

        if (!$registration->isReserved() || Event::STATUS_COMPLETED === $event->getStatus()) {
            throw new ValidationException('Ce choix n\'est plus modifiable.', ['consent' => ['L\'inscription est annulée ou l\'événement est terminé.']], 'broadcast_consent_closed');
        }

        $registration->chooseBroadcastConsent($consent, $this->clock->now());
        $this->registrationRepository->flush();

        $this->logger->info('registration.broadcast_consent', ['registrationId' => $registrationId, 'consent' => $consent]);
    }
}
