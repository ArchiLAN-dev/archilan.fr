<?php

declare(strict_types=1);

namespace App\Registrations\Application\Command;

use App\Events\Application\Message\EventCapacityReachedMessage;
use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Realtime\Application\Service\RealtimePublisher;
use App\Registrations\Application\Exception\WarningsToAcknowledgeException;
use App\Registrations\Application\Query\PrivateEventAccess;
use App\Registrations\Application\Query\RegistrationCounter;
use App\Registrations\Application\Support\AdminRegistrationWarning;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Shared\Application\Exception\ApplicationFailure;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Story 5.10: an admin registers a member, or puts back a cancelled registration, on the member's behalf. The rules a
 * member meets (window, capacity, verified email, private access, event status) are warnings here, not refusals; only
 * what would leave a registration unusable blocks (unknown, deleted or banned member, missing or completed event).
 */
final readonly class AdminRegistrationCreation
{
    public function __construct(
        private RegistrationRepositoryInterface $registrationRepository,
        private EventRepositoryInterface $eventRepository,
        private UserRepositoryInterface $userRepository,
        private RegistrationCounter $registrationCounter,
        private PrivateEventAccess $privateEventAccess,
        private RealtimePublisher $realtimePublisher,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Registers the member. A cancelled registration of theirs comes back as it was (one row per member and event).
     *
     * @throws NotFoundException              when the event or the member does not exist
     * @throws ConflictException              when the event is completed or the member is banned
     * @throws WarningsToAcknowledgeException when member-side rules are crossed and not acknowledged
     */
    public function create(string $eventId, string $userId, string $adminId, bool $acknowledgeWarnings): AdminRegistrationCreated
    {
        $user = $this->requireRegistrableUser('create', $eventId, $userId, $adminId);

        return $this->underEventLock($eventId, $adminId, 'create', function (Event $event) use ($user, $adminId, $acknowledgeWarnings): AdminRegistrationCreated {
            $existing = $this->registrationRepository->findByEventAndUser($event->getId(), $user->getId());

            if ($existing instanceof Registration && $existing->isReserved()) {
                return new AdminRegistrationCreated($existing->getId(), AdminRegistrationOutcome::AlreadyRegistered, []);
            }

            $warnings = $this->warnings($event, $user);
            WarningsToAcknowledgeException::unlessAcknowledged($warnings, $acknowledgeWarnings);

            $now = $this->clock->now();
            if ($existing instanceof Registration) {
                $existing->restoreByAdmin($now);

                return new AdminRegistrationCreated($existing->getId(), AdminRegistrationOutcome::Restored, $warnings);
            }

            $registration = new Registration(
                bin2hex(random_bytes(16)),
                $event->getId(),
                $user->getId(),
                Registration::STATUS_RESERVED,
                $now,
                $now,
                createdByAdminId: $adminId,
            );
            $this->registrationRepository->persist($registration);

            return new AdminRegistrationCreated($registration->getId(), AdminRegistrationOutcome::Created, $warnings);
        });
    }

    /**
     * Puts a cancelled registration back, with its games, YAML and confirmation (a release at closing included).
     *
     * @throws NotFoundException              when the registration does not exist for this event
     * @throws ConflictException              when it is still active, the event is completed or the member is banned
     * @throws WarningsToAcknowledgeException when member-side rules are crossed and not acknowledged
     */
    public function reactivate(string $eventId, string $registrationId, string $adminId, bool $acknowledgeWarnings): void
    {
        $registration = $this->registrationRepository->findById($registrationId);

        if (null === $registration || $registration->getEventId() !== $eventId) {
            $this->auditLog('reactivate', $eventId, $registrationId, $adminId, 'not_found', []);

            throw new NotFoundException('Inscription introuvable.');
        }

        if ($registration->isReserved()) {
            $this->auditLog('reactivate', $eventId, $registrationId, $adminId, 'already_active', []);

            throw new ConflictException('L\'inscription est déjà active.', 'already_active');
        }

        $user = $this->requireRegistrableUser('reactivate', $eventId, $registration->getUserId(), $adminId);

        $this->underEventLock($eventId, $adminId, 'reactivate', function (Event $event) use ($registration, $user, $acknowledgeWarnings): AdminRegistrationCreated {
            $warnings = $this->warnings($event, $user);
            WarningsToAcknowledgeException::unlessAcknowledged($warnings, $acknowledgeWarnings);

            $registration->restoreByAdmin($this->clock->now());

            return new AdminRegistrationCreated($registration->getId(), AdminRegistrationOutcome::Restored, $warnings);
        });
    }

    /**
     * Runs the write under the event's exclusive lock (the capacity count must not race a member's reservation), then
     * publishes the seat counter and the admin feed once committed.
     *
     * @param \Closure(Event): AdminRegistrationCreated $write
     */
    private function underEventLock(string $eventId, string $adminId, string $action, \Closure $write): AdminRegistrationCreated
    {
        $this->registrationRepository->beginTransaction();

        try {
            $event = $this->registrationRepository->findEventWithExclusiveLock($eventId);

            if (!$event instanceof Event) {
                $this->registrationRepository->rollBack();
                $this->auditLog($action, $eventId, null, $adminId, 'event_not_found', []);

                throw new NotFoundException('Événement introuvable.');
            }

            if (Event::STATUS_COMPLETED === $event->getStatus()) {
                $this->registrationRepository->rollBack();
                $this->auditLog($action, $eventId, null, $adminId, 'event_completed', []);

                throw new ConflictException('L\'événement est terminé.', 'event_completed');
            }

            $result = $write($event);

            $this->registrationRepository->flush();
            $this->registrationRepository->commit();
        } catch (WarningsToAcknowledgeException $e) {
            $this->registrationRepository->rollBack();
            $this->auditLog($action, $eventId, null, $adminId, 'warnings_to_acknowledge', []);

            throw $e;
        } catch (ApplicationFailure $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->registrationRepository->rollBack();

            throw $e;
        }

        $this->auditLog($action, $eventId, $result->registrationId, $adminId, $result->outcome->value, $result->acknowledgedWarnings);

        if (AdminRegistrationOutcome::AlreadyRegistered !== $result->outcome) {
            $this->afterCommit($event, $result->registrationId);
        }

        return $result;
    }

    private function afterCommit(Event $event, string $registrationId): void
    {
        $now = $this->clock->now();
        $count = $this->registrationCounter->countConfirmed($event->getId());

        $this->realtimePublisher->seatCounter($event->getId(), max(0, $event->getCapacity() - $count));
        $this->realtimePublisher->adminRegistrationCreated($event->getId(), $registrationId, $now);

        if ($event->isAtCapacity($count) && !$event->isCapacityNotificationSent()) {
            try {
                $event->markCapacityNotificationSent($now);
                $this->eventRepository->save($event);
                $this->messageBus->dispatch(new EventCapacityReachedMessage(
                    eventId: $event->getId(),
                    eventTitle: $event->getTitle(),
                    capacity: $event->getCapacity(),
                ));
            } catch (\Throwable $e) {
                $this->logger->error('admin.capacity_notification_dispatch_failed', ['eventId' => $event->getId(), 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @return list<AdminRegistrationWarning>
     */
    private function warnings(Event $event, User $user): array
    {
        $now = $this->clock->now();
        $warnings = [];

        if (Event::STATUS_DRAFT === $event->getStatus()) {
            $warnings[] = AdminRegistrationWarning::EventNotPublished;
        }
        if (Event::STATUS_IN_PROGRESS === $event->getStatus()) {
            $warnings[] = AdminRegistrationWarning::EventInProgress;
        }
        if ($now < $event->getRegistrationOpensAt()) {
            $warnings[] = AdminRegistrationWarning::RegistrationNotOpenYet;
        }
        if ($now > $event->getRegistrationClosesAt()) {
            $warnings[] = AdminRegistrationWarning::RegistrationClosed;
        }
        if ($this->registrationCounter->countConfirmed($event->getId()) >= $event->getCapacity()) {
            $warnings[] = AdminRegistrationWarning::CapacityFull;
        }
        if (!$user->isEmailVerified()) {
            $warnings[] = AdminRegistrationWarning::EmailNotVerified;
        }
        if (!$this->privateEventAccess->allows($event, $user->getId())) {
            $warnings[] = AdminRegistrationWarning::PrivateEvent;
        }

        return $warnings;
    }

    /**
     * @throws NotFoundException when the member does not exist or deleted their account
     * @throws ConflictException when the member is banned
     */
    private function requireRegistrableUser(string $action, string $eventId, string $userId, string $adminId): User
    {
        $user = $this->userRepository->findById($userId);

        if (!$user instanceof User || null !== $user->getDeletedAt()) {
            $this->auditLog($action, $eventId, null, $adminId, 'user_not_found', []);

            throw new NotFoundException('Joueur introuvable.');
        }

        if (null !== $user->getBannedAt()) {
            $this->auditLog($action, $eventId, null, $adminId, 'user_banned', []);

            throw new ConflictException('Ce joueur est banni.', 'user_banned');
        }

        return $user;
    }

    /**
     * @param list<AdminRegistrationWarning> $acknowledged
     */
    private function auditLog(string $action, string $eventId, ?string $registrationId, string $adminId, string $outcome, array $acknowledged): void
    {
        $this->logger->info('admin.registrations.'.$action, [
            'eventId' => $eventId,
            'registrationId' => $registrationId,
            'adminId' => $adminId,
            'outcome' => $outcome,
            'acknowledgedWarnings' => array_map(static fn (AdminRegistrationWarning $w): string => $w->value, $acknowledged),
            'occurredAt' => $this->clock->now()->format(\DateTimeInterface::ATOM),
        ]);
    }
}
