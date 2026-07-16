<?php

declare(strict_types=1);

namespace App\Registrations\Application\Command;

use App\Events\Application\Message\EventCapacityReachedMessage;
use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Realtime\Application\Service\RealtimePublisher;
use App\Registrations\Application\Query\RegistrationCounter;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Shared\Application\Exception\ApplicationFailure;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ReserveRegistration
{
    public function __construct(
        private RegistrationRepositoryInterface $registrationRepository,
        private EventRepositoryInterface $eventRepository,
        private UserRepositoryInterface $userRepository,
        private RegistrationCounter $registrationCounter,
        private RealtimePublisher $realtimePublisher,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Reserves a seat on an event for the given authenticated user.
     * Returns null if the event does not exist or is not publicly visible.
     *
     * `reserved` (201) and `already_registered` (200) are both success outcomes; the four failure paths throw.
     *
     * @return array{outcome: 'reserved'|'already_registered', registrationId: string}
     *
     * @throws ForbiddenException  when the caller's email is not verified
     * @throws NotFoundException   when the event does not exist or is not public
     * @throws ValidationException when the caller is not eligible to register
     * @throws ConflictException   when the event is at capacity
     */
    public function reserve(string $eventId, string $userId): array
    {
        $user = $this->userRepository->findById($userId);

        if (!$user instanceof User || !$user->isEmailVerified()) {
            throw new ForbiddenException('Tu dois confirmer ton adresse email avant de t\'inscrire à un événement.', 'email_not_verified');
        }

        $this->registrationRepository->beginTransaction();

        $registration = null;
        $confirmedCount = 0;
        $lockedEvent = null;

        try {
            $lockedEvent = $this->registrationRepository->findEventWithExclusiveLock($eventId);

            if (!$lockedEvent instanceof Event) {
                $this->registrationRepository->rollBack();

                throw new NotFoundException('Événement introuvable.');
            }

            if (!$lockedEvent->isVisiblePublicly()) {
                $this->registrationRepository->commit();

                throw new NotFoundException('Événement introuvable.');
            }

            $now = $this->clock->now();
            $ineligibleReason = $this->computeIneligibleReason($lockedEvent, $now);

            if (null !== $ineligibleReason) {
                $this->registrationRepository->commit();

                throw new ValidationException("L'inscription n'est pas disponible pour cet événement.", ['registration' => [$ineligibleReason]], 'not_eligible');
            }

            $existing = $this->registrationRepository->findByEventAndUser($lockedEvent->getId(), $userId);

            if ($existing instanceof Registration && Registration::STATUS_CANCELLED !== $existing->getStatus()) {
                $this->registrationRepository->commit();

                return ['outcome' => 'already_registered', 'registrationId' => $existing->getId()];
            }

            $confirmedCount = $this->registrationCounter->countConfirmed($lockedEvent->getId());

            if ($confirmedCount >= $lockedEvent->getCapacity()) {
                $this->registrationRepository->commit();

                throw new ConflictException('Cet événement est complet.', 'capacity_full');
            }

            $registration = new Registration(
                bin2hex(random_bytes(16)),
                $lockedEvent->getId(),
                $userId,
                Registration::STATUS_RESERVED,
                $now,
                $now,
            );
            $this->registrationRepository->persist($registration);
            $this->registrationRepository->flush();
            $this->registrationRepository->commit();
        } catch (ApplicationFailure $e) {
            // Business failures manage their own commit/rollback before throwing; do not roll back again.
            throw $e;
        } catch (\Throwable $e) {
            $this->registrationRepository->rollBack();
            throw $e;
        }

        $registrationId = $registration->getId();
        $newCount = $confirmedCount + 1;
        $remaining = max(0, $lockedEvent->getCapacity() - $newCount);

        $this->dispatchCapacityNotificationIfNeeded($lockedEvent, $newCount, $this->clock->now());
        $this->realtimePublisher->seatCounter($lockedEvent->getId(), $remaining);
        $this->realtimePublisher->adminRegistrationCreated($lockedEvent->getId(), $registrationId, $this->clock->now());

        return ['outcome' => 'reserved', 'registrationId' => $registrationId];
    }

    private function dispatchCapacityNotificationIfNeeded(Event $event, int $confirmedCount, \DateTimeImmutable $now): void
    {
        if (!$event->isAtCapacity($confirmedCount) || $event->isCapacityNotificationSent()) {
            return;
        }

        try {
            $event->markCapacityNotificationSent($now);
            $this->eventRepository->save($event);
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
