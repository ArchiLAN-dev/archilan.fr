<?php

declare(strict_types=1);

namespace App\Registrations\Application\Handler;

use App\Communications\Application\Message\EventEveReminderMessage;
use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Registrations\Application\Message\SendEventEveRemindersMessage;
use App\Registrations\Application\Query\RegistrantChecklistQuery;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Story 53.8: the day before, each confirmed registrant of a published event gets one mail with the start, the venue
 * and what is left to do. The registrations are marked first, in one flush; the mails go after, best effort, so a
 * failing one never undoes the others' mark and a second pass sends nothing again. A registration confirmed once its
 * event starts in less than 12 hours gets no mail.
 */
#[AsMessageHandler]
final readonly class SendEventEveRemindersHandler
{
    /** The window of an event's start, from now: an hourly pass catches each event about a day ahead. */
    private const string WINDOW_FROM = '+12 hours';
    private const string WINDOW_TO = '+36 hours';

    /** What the mail says for each required step still to do (the keys of RegistrantChecklist). */
    private const array STEP_LABELS = [
        'confirm' => 'Confirmer ton inscription',
        'games' => 'Choisir tes jeux',
        'yaml' => 'Régler les options de tes jeux',
        'registration_questions' => "Répondre aux questions de l'orga",
        'payment' => 'Payer ta place',
    ];

    public function __construct(
        private EventRepositoryInterface $events,
        private RegistrationRepositoryInterface $registrations,
        private UserRepositoryInterface $users,
        private RegistrantChecklistQuery $checklist,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private string $siteUrl,
    ) {
    }

    public function __invoke(SendEventEveRemindersMessage $message): void
    {
        $now = $this->clock->now();
        $from = $now->modify(self::WINDOW_FROM);
        $to = $now->modify(self::WINDOW_TO);

        foreach ($this->events->findByStatuses([Event::STATUS_PUBLISHED, Event::STATUS_IN_PROGRESS]) as $event) {
            $startsAt = $event->getStartsAt();
            if ($event->isEveReminderEnabled() && $startsAt >= $from && $startsAt <= $to) {
                $this->remind($event, $now);
            }
        }
    }

    private function remind(Event $event, \DateTimeImmutable $now): void
    {
        $due = array_values(array_filter(
            $this->registrations->findBy(['eventId' => $event->getId(), 'status' => Registration::STATUS_RESERVED]),
            static fn (Registration $registration): bool => $registration->needsEveReminder(),
        ));
        if ([] === $due) {
            return;
        }

        foreach ($due as $registration) {
            $registration->markEveReminded($now);
        }
        $this->registrations->flush();

        $startsAt = $event->getStartsAt()->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y \à H\hi');
        $hubUrl = sprintf('%s/evenements/%s/mon-event', rtrim($this->siteUrl, '/'), $event->getId());
        foreach ($due as $registration) {
            $this->notify($registration, $event, $startsAt, $hubUrl);
        }

        $this->logger->info('registration.eve_reminders_sent', ['eventId' => $event->getId(), 'count' => count($due)]);
    }

    /** An e-mail is best effort, after the write: a deleted account or a failing transport never undoes it. */
    private function notify(Registration $registration, Event $event, string $startsAt, string $hubUrl): void
    {
        $user = $this->users->findById($registration->getUserId());
        if (!$user instanceof User || null !== $user->getDeletedAt() || '' === $user->getEmail()) {
            return;
        }

        try {
            $this->messageBus->dispatch(new EventEveReminderMessage(
                $user->getEmail(),
                $user->getDisplayName(),
                $event->getTitle(),
                $startsAt,
                $event->getVenue(),
                $this->stepsLeft($registration),
                $hubUrl,
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('registration.eve_reminder_failed', ['registrationId' => $registration->getId(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * The required steps still to do, worded for the mail. The checklist is read as its owner would; when it cannot be
     * read, the mail goes without it rather than not at all.
     *
     * @return list<string>
     */
    private function stepsLeft(Registration $registration): array
    {
        try {
            $items = $this->checklist->forOwner($registration->getId(), $registration->getUserId())['items'];
        } catch (\Throwable $e) {
            $this->logger->warning('registration.eve_reminder_checklist_unavailable', ['registrationId' => $registration->getId(), 'error' => $e->getMessage()]);

            return [];
        }

        $steps = [];
        foreach ($items as $item) {
            $label = self::STEP_LABELS[$item['key']] ?? null;
            if ($item['required'] && 'todo' === $item['state'] && null !== $label) {
                $steps[] = $label;
            }
        }

        return $steps;
    }
}
