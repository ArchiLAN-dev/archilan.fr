<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Events\Application\EventCapacityReachedMessage;
use App\Events\Domain\Event;
use App\Realtime\Application\RealtimePublisher;
use App\Registrations\Domain\Registration;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ReserveRegistration
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RegistrationCounter $registrationCounter,
        private RealtimePublisher $realtimePublisher,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Reserves a seat on an event for the given authenticated user.
     * Returns null if the event does not exist or is not publicly visible.
     *
     * @return array{outcome: 'not_eligible', reason: string}|array{outcome: 'capacity_full'}|array{outcome: 'reserved', registrationId: string}|array{outcome: 'already_registered', registrationId: string}|null
     */
    public function reserve(string $eventId, string $userId): ?array
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $lockedEvent = $this->entityManager->find(Event::class, $eventId, LockMode::PESSIMISTIC_WRITE);

            if (!$lockedEvent instanceof Event) {
                $connection->rollBack();

                return null;
            }

            $this->entityManager->refresh($lockedEvent, LockMode::PESSIMISTIC_WRITE);

            if (!$lockedEvent->isVisiblePublicly()) {
                $connection->commit();

                return null;
            }

            $now = new \DateTimeImmutable();
            $ineligibleReason = $this->computeIneligibleReason($lockedEvent, $now);

            if (null !== $ineligibleReason) {
                $connection->commit();

                return ['outcome' => 'not_eligible', 'reason' => $ineligibleReason];
            }

            $existing = $this->entityManager->getRepository(Registration::class)->findOneBy([
                'eventId' => $lockedEvent->getId(),
                'userId' => $userId,
            ]);

            if ($existing instanceof Registration && Registration::STATUS_CANCELLED !== $existing->getStatus()) {
                $connection->commit();

                return ['outcome' => 'already_registered', 'registrationId' => $existing->getId()];
            }

            $confirmedCount = $this->registrationCounter->countConfirmed($lockedEvent->getId());

            if ($confirmedCount >= $lockedEvent->getCapacity()) {
                $connection->commit();

                return ['outcome' => 'capacity_full'];
            }

            $registration = new Registration(
                bin2hex(random_bytes(16)),
                $lockedEvent->getId(),
                $userId,
                Registration::STATUS_RESERVED,
                $now,
                $now,
            );
            $this->entityManager->persist($registration);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        $registrationId = $registration->getId();
        $newCount = $confirmedCount + 1;
        $remaining = max(0, $lockedEvent->getCapacity() - $newCount);

        $this->dispatchCapacityNotificationIfNeeded($lockedEvent, $newCount, $now);
        $this->realtimePublisher->seatCounter($lockedEvent->getId(), $remaining);
        $this->realtimePublisher->adminRegistrationCreated($lockedEvent->getId(), $registrationId, $now);

        return ['outcome' => 'reserved', 'registrationId' => $registrationId];
    }

    private function dispatchCapacityNotificationIfNeeded(Event $event, int $confirmedCount, \DateTimeImmutable $now): void
    {
        if (!$event->isAtCapacity($confirmedCount) || $event->isCapacityNotificationSent()) {
            return;
        }

        try {
            $event->markCapacityNotificationSent($now);
            $this->entityManager->flush();
            $this->messageBus->dispatch(new EventCapacityReachedMessage(
                eventId: $event->getId(),
                eventTitle: $event->getTitle(),
                capacity: $event->getCapacity(),
            ));
        } catch (\Throwable $e) {
            $this->logger->error('admin.capacity_notification_dispatch_failed', [
                'eventId' => $event->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function computeIneligibleReason(Event $event, \DateTimeImmutable $now): ?string
    {
        if (!$event->isPublic()) {
            return 'private_event';
        }

        if (in_array($event->getStatus(), [Event::STATUS_COMPLETED, Event::STATUS_IN_PROGRESS], true)) {
            return 'event_not_open';
        }

        if ($now < $event->getRegistrationOpensAt()) {
            return 'registration_not_open_yet';
        }

        if ($now > $event->getRegistrationClosesAt()) {
            return 'registration_closed';
        }

        return null;
    }
}
