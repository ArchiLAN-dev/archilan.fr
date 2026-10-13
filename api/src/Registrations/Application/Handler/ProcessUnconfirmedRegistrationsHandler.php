<?php

declare(strict_types=1);

namespace App\Registrations\Application\Handler;

use App\Communications\Application\Message\RegistrationConfirmationReminderMessage;
use App\Communications\Application\Message\RegistrationReleasedMessage;
use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Realtime\Application\Service\RealtimePublisher;
use App\Registrations\Application\Message\ProcessUnconfirmedRegistrationsMessage;
use App\Registrations\Application\Query\RegistrationCounter;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Story 4.23: a reserved registration nobody confirmed holds a seat without giving access to the event's game. Two
 * days before registrations close its member is reminded once; when they close, it is cancelled and the seat freed.
 * Each pass only acts on what is still pending, so running it again changes nothing.
 */
#[AsMessageHandler]
final readonly class ProcessUnconfirmedRegistrationsHandler
{
    private const string REMINDER_LEAD = '-48 hours';

    public function __construct(
        private EventRepositoryInterface $events,
        private RegistrationRepositoryInterface $registrations,
        private UserRepositoryInterface $users,
        private RegistrationCounter $registrationCounter,
        private RealtimePublisher $realtimePublisher,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private string $siteUrl,
    ) {
    }

    public function __invoke(ProcessUnconfirmedRegistrationsMessage $message): void
    {
        $now = $this->clock->now();

        foreach ($this->events->findByStatus(Event::STATUS_PUBLISHED) as $event) {
            $closesAt = $event->getRegistrationClosesAt();
            $remindFrom = $closesAt->modify(self::REMINDER_LEAD);

            if ($now > $closesAt) {
                $this->release($event, $now);
            } elseif ($now >= $remindFrom) {
                $this->remind($event, $remindFrom, $now);
            }
        }
    }

    private function remind(Event $event, \DateTimeImmutable $remindFrom, \DateTimeImmutable $now): void
    {
        $due = array_values(array_filter(
            $this->pending($event),
            static fn (Registration $registration): bool => $registration->needsConfirmationReminder($remindFrom),
        ));
        if ([] === $due) {
            return;
        }

        foreach ($due as $registration) {
            $registration->markReminded($now);
        }
        $this->registrations->flush();

        $closesAt = $event->getRegistrationClosesAt()->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y \à H\hi');
        foreach ($due as $registration) {
            $this->notify($registration, fn (User $user): object => new RegistrationConfirmationReminderMessage(
                $user->getEmail(),
                $user->getDisplayName(),
                $event->getTitle(),
                $closesAt,
                sprintf('%s/evenements/%s/inscription/%s/recap', rtrim($this->siteUrl, '/'), $event->getId(), $registration->getId()),
            ));
        }

        $this->logger->info('registration.confirmation_reminders_sent', ['eventId' => $event->getId(), 'count' => count($due)]);
    }

    private function release(Event $event, \DateTimeImmutable $now): void
    {
        $released = $this->pending($event);
        if ([] === $released) {
            return;
        }

        foreach ($released as $registration) {
            $registration->cancel($now);
        }
        $this->registrations->flush();

        $remaining = max(0, $event->getCapacity() - $this->registrationCounter->countConfirmed($event->getId()));
        $this->realtimePublisher->seatCounter($event->getId(), $remaining);

        foreach ($released as $registration) {
            $this->notify($registration, fn (User $user): object => new RegistrationReleasedMessage(
                $user->getEmail(),
                $user->getDisplayName(),
                $event->getTitle(),
                sprintf('%s/evenements/%s', rtrim($this->siteUrl, '/'), $event->getId()),
            ));
        }

        $this->logger->info('registration.unconfirmed_released', ['eventId' => $event->getId(), 'count' => count($released)]);
    }

    /**
     * @return list<Registration>
     */
    private function pending(Event $event): array
    {
        return array_values(array_filter(
            $this->registrations->findBy(['eventId' => $event->getId(), 'status' => Registration::STATUS_RESERVED]),
            static fn (Registration $registration): bool => $registration->isAwaitingConfirmation(),
        ));
    }

    /**
     * An e-mail is best effort, after the write: a deleted account or a failing transport never undoes it.
     *
     * @param \Closure(User): object $message
     */
    private function notify(Registration $registration, \Closure $message): void
    {
        $user = $this->users->findById($registration->getUserId());
        if (!$user instanceof User || null !== $user->getDeletedAt() || '' === $user->getEmail()) {
            return;
        }

        try {
            $this->messageBus->dispatch($message($user));
        } catch (\Throwable $e) {
            $this->logger->warning('registration.unconfirmed_notification_failed', ['registrationId' => $registration->getId(), 'error' => $e->getMessage()]);
        }
    }
}
