<?php

declare(strict_types=1);

namespace App\Registrations\Application\Command;

use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Realtime\Application\Service\RealtimePublisher;
use App\Registrations\Application\Query\RegistrationCounter;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class AdminRegistrationCancellation
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
     * @throws NotFoundException when the registration does not exist for this event
     * @throws ConflictException when the registration is already cancelled
     */
    public function cancel(string $eventId, string $registrationId, string $adminId): void
    {
        $registration = $this->registrationRepository->findById($registrationId);

        if (null === $registration || $registration->getEventId() !== $eventId) {
            $this->auditLog($eventId, $registrationId, $adminId, 'not_found');

            throw new NotFoundException('Inscription introuvable.');
        }

        if (!$registration->isReserved()) {
            $this->auditLog($eventId, $registrationId, $adminId, 'already_cancelled');

            throw new ConflictException('L\'inscription est déjà annulée.', 'already_cancelled');
        }

        $now = $this->clock->now();
        $registration->cancel($now);

        $event = $this->eventRepository->findById($eventId);
        $this->registrationRepository->flush();

        $this->logger->info('registration.admin_cancelled', ['registrationId' => $registrationId, 'eventId' => $eventId]);
        $this->auditLog($eventId, $registrationId, $adminId, 'cancelled');

        if (null !== $event) {
            $remaining = max(0, $event->getCapacity() - $this->registrationCounter->countConfirmed($eventId));
            $this->realtimePublisher->seatCounter($event->getId(), $remaining);
        }
    }

    private function auditLog(string $eventId, string $registrationId, string $adminId, string $outcome): void
    {
        $this->logger->info('admin.registrations.cancel', [
            'eventId' => $eventId,
            'registrationId' => $registrationId,
            'adminId' => $adminId,
            'outcome' => $outcome,
            'occurredAt' => $this->clock->now()->format(\DateTimeInterface::ATOM),
        ]);
    }
}
