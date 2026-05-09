<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Events\Domain\Event;
use App\Realtime\Application\RealtimePublisher;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class RegistrationCancellation
{
    public function __construct(
        private EntityManagerInterface $entityManager,
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
        $registration = $this->entityManager->find(Registration::class, $registrationId);

        if (!$registration instanceof Registration || $registration->getUserId() !== $userId || !$registration->isReserved()) {
            return null;
        }

        $event = $this->entityManager->find(Event::class, $registration->getEventId());

        if (!$event instanceof Event) {
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
        $this->entityManager->flush();

        $this->logger->info('registration.cancelled', ['registrationId' => $registrationId, 'userId' => $userId, 'eventId' => $registration->getEventId()]);

        $remaining = max(0, $event->getCapacity() - $this->registrationCounter->countConfirmed($event->getId()));
        $this->realtimePublisher->seatCounter($event->getId(), $remaining);

        return ['outcome' => 'cancelled'];
    }
}
