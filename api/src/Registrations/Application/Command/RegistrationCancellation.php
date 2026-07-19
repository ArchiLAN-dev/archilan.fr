<?php

declare(strict_types=1);

namespace App\Registrations\Application\Command;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Realtime\Application\Service\RealtimePublisher;
use App\Registrations\Application\Query\RegistrationCounter;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class RegistrationCancellation
{
    public function __construct(
        private RegistrationRepositoryInterface $registrationRepository,
        private EventRepositoryInterface $eventRepository,
        private RegistrationCounter $registrationCounter,
        private RealtimePublisher $realtimePublisher,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Cancels a registration on behalf of the registrant. Returns null if the registration
     * does not exist, does not belong to the given user, or is not in reserved status.
     *
     * @throws NotFoundException   when the registration does not exist or is not the caller's reserved one
     * @throws ValidationException when cancellation is no longer allowed
     */
    public function cancel(string $registrationId, string $userId): void
    {
        $registration = $this->registrationRepository->findById($registrationId);

        if (null === $registration) {
            throw new NotFoundException('Inscription introuvable.');
        }

        if ($registration->getUserId() !== $userId || !$registration->isReserved()) {
            throw new NotFoundException('Inscription introuvable.');
        }

        $event = $this->eventRepository->findById($registration->getEventId());

        if (null === $event) {
            throw new NotFoundException('Inscription introuvable.');
        }

        if (in_array($event->getStatus(), [Event::STATUS_IN_PROGRESS, Event::STATUS_COMPLETED], true)) {
            throw new ValidationException('L\'annulation n\'est plus possible une fois l\'événement commencé.', [], 'cancellation_not_allowed');
        }

        $now = $this->clock->now();
        $registration->cancel($now);
        $this->registrationRepository->flush();

        $this->logger->info('registration.cancelled', ['registrationId' => $registrationId, 'userId' => $userId, 'eventId' => $registration->getEventId()]);

        $remaining = max(0, $event->getCapacity() - $this->registrationCounter->countConfirmed($event->getId()));
        $this->realtimePublisher->seatCounter($event->getId(), $remaining);
    }
}
