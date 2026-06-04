<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Events\Domain\Event;
use App\Events\Domain\EventRepositoryInterface;
use App\Realtime\Application\RealtimePublisher;
use App\Registrations\Domain\RegistrationRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class RegistrationCancellation
{
    public function __construct(
        private RegistrationRepositoryInterface $registrationRepository,
        private EventRepositoryInterface $eventRepository,
        private RegistrationCounter $registrationCounter,
        private RealtimePublisher $realtimePublisher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Cancels a registration on behalf of the registrant. Returns null if the registration
     * does not exist, does not belong to the given user, or is not in reserved status.
     *
     * @return array{outcome: 'cancelled'}|array{outcome: 'error', code: string, message: string}|null
     */
    public function cancel(string $registrationId, string $userId): ?array
    {
        $registration = $this->registrationRepository->findById($registrationId);

        if (null === $registration) {
            return null;
        }

        if ($registration->getUserId() !== $userId || !$registration->isReserved()) {
            return null;
        }

        $event = $this->eventRepository->findById($registration->getEventId());

        if (null === $event) {
            return null;
        }

        if (in_array($event->getStatus(), [Event::STATUS_IN_PROGRESS, Event::STATUS_COMPLETED], true)) {
            return [
                'outcome' => 'error',
                'code' => 'cancellation_not_allowed',
                'message' => 'L\'annulation n\'est plus possible une fois l\'événement commencé.',
            ];
        }

        $now = new \DateTimeImmutable();
        $registration->cancel($now);
        $this->registrationRepository->flush();

        $this->logger->info('registration.cancelled', ['registrationId' => $registrationId, 'userId' => $userId, 'eventId' => $registration->getEventId()]);

        $remaining = max(0, $event->getCapacity() - $this->registrationCounter->countConfirmed($event->getId()));
        $this->realtimePublisher->seatCounter($event->getId(), $remaining);

        return ['outcome' => 'cancelled'];
    }
}
