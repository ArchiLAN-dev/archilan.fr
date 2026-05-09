<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Events\Domain\Event;
use App\Realtime\Application\RealtimePublisher;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class AdminRegistrationCancellation
{
    public function __construct(
        private EntityManagerInterface $entityManager,
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
        $registration = $this->entityManager->find(Registration::class, $registrationId);

        if (!$registration instanceof Registration || $registration->getEventId() !== $eventId) {
            return ['outcome' => 'not_found'];
        }

        if (!$registration->isReserved()) {
            return ['outcome' => 'already_cancelled'];
        }

        $now = new \DateTimeImmutable();
        $registration->cancel($now);

        $event = $this->entityManager->find(Event::class, $eventId);
        $this->entityManager->flush();

        $this->logger->info('registration.admin_cancelled', ['registrationId' => $registrationId, 'eventId' => $eventId]);

        if ($event instanceof Event) {
            $remaining = max(0, $event->getCapacity() - $this->registrationCounter->countConfirmed($eventId));
            $this->realtimePublisher->seatCounter($event->getId(), $remaining);
        }

        return ['outcome' => 'cancelled'];
    }
}
