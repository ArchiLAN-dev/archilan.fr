<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Events\Domain\EventRepositoryInterface;
use App\Realtime\Application\RealtimePublisher;
use App\Registrations\Domain\RegistrationRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class AdminRegistrationCancellation
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
     * @return array{outcome: 'cancelled'}|array{outcome: 'not_found'}|array{outcome: 'already_cancelled'}
     */
    public function cancel(string $eventId, string $registrationId): array
    {
        $registration = $this->registrationRepository->findById($registrationId);

        if (null === $registration) {
            return ['outcome' => 'not_found'];
        }

        if ($registration->getEventId() !== $eventId) {
            return ['outcome' => 'not_found'];
        }

        if (!$registration->isReserved()) {
            return ['outcome' => 'already_cancelled'];
        }

        $now = new \DateTimeImmutable();
        $registration->cancel($now);

        $event = $this->eventRepository->findById($eventId);
        $this->registrationRepository->flush();

        $this->logger->info('registration.admin_cancelled', ['registrationId' => $registrationId, 'eventId' => $eventId]);

        if (null !== $event) {
            $remaining = max(0, $event->getCapacity() - $this->registrationCounter->countConfirmed($eventId));
            $this->realtimePublisher->seatCounter($event->getId(), $remaining);
        }

        return ['outcome' => 'cancelled'];
    }
}
