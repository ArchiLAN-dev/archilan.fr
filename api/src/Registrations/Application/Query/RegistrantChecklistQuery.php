<?php

declare(strict_types=1);

namespace App\Registrations\Application\Query;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Events\Domain\Service\EventPhase;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Payments\Application\Query\HelloAssoPaymentLookup;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationPresenceWindowRepositoryInterface;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Registrations\Domain\Service\RegistrantChecklist;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use Psr\Clock\ClockInterface;

/**
 * Story 53.2: what a registrant still has to do for an event, for their hub (and the eve-of-event mail, 53.8).
 *
 * @phpstan-import-type ChecklistItem from RegistrantChecklist
 */
final readonly class RegistrantChecklistQuery
{
    public function __construct(
        private RegistrationRepositoryInterface $registrations,
        private EventRepositoryInterface $events,
        private GameRepositoryInterface $games,
        private UserRepositoryInterface $users,
        private RegistrationPresenceWindowRepositoryInterface $presenceWindows,
        private PendingRegistrationSurveyQueryInterface $pendingRegistrationSurveys,
        private RegistrantSurveysQueryInterface $registrantSurveys,
        private HelloAssoPaymentLookup $payments,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{phase: string, items: list<ChecklistItem>}
     */
    public function forOwner(string $registrationId, string $userId): array
    {
        $registration = $this->registrations->findById($registrationId);
        if (!$registration instanceof Registration || !$registration->isReserved()) {
            throw new NotFoundException('Inscription introuvable.', 'registration_not_found');
        }
        if ($registration->getUserId() !== $userId) {
            throw new ForbiddenException("Cette inscription n'est pas la tienne.");
        }

        $event = $this->events->findById($registration->getEventId());
        $user = $this->users->findById($userId);
        if (!$event instanceof Event || !$user instanceof User) {
            throw new NotFoundException('Inscription introuvable.', 'registration_not_found');
        }

        return [
            'phase' => EventPhase::of($event, $this->clock->now()),
            'items' => $this->items($registration, $event, $user),
        ];
    }

    /**
     * @return list<ChecklistItem>
     */
    private function items(Registration $registration, Event $event, User $user): array
    {
        $now = $this->clock->now();
        $eventId = $event->getId();
        [$configurableSlots, $slotsMissingYaml] = $this->yamlProgress($registration);

        $registrationQuestionsDone = $this->registrantSurveys->hasOpenRegistrationSurvey($eventId, $now)
            ? !$this->pendingRegistrationSurveys->hasUnansweredRequired($eventId, $user->getId(), $now)
            : null;

        $paymentExpected = null !== $event->getHelloassoFormSlug();
        $payment = $paymentExpected ? $this->payments->findForEventAndEmail($eventId, $user->getEmail()) : null;

        return RegistrantChecklist::build(
            confirmed: null !== $registration->getSubmittedAt(),
            gameSelectionEnabled: $event->isGameSelectionEnabled(),
            slots: count($registration->getGameSlots()),
            configurableSlots: $configurableSlots,
            slotsMissingYaml: $slotsMissingYaml,
            registrationQuestionsDone: $registrationQuestionsDone,
            pendingSurveys: $this->registrantSurveys->countPendingForRegistrant($eventId, $user->getId(), $now),
            paymentExpected: $paymentExpected,
            paymentStatus: $payment['status'] ?? null,
            paymentStale: $payment['isStale'] ?? false,
            hasPresence: [] !== $this->presenceWindows->findByRegistration($registration->getId()),
            discordLinked: null !== $user->getDiscordId(),
        );
    }

    /**
     * Same reading as the registration recap (`resolveSlotYamlStatus`): only a slot whose apworld is ready can be
     * configured, and it waits for the player only when the game offers no default options.
     *
     * @return array{int, list<string>}
     */
    private function yamlProgress(Registration $registration): array
    {
        $slots = $registration->getGameSlots();
        if ([] === $slots) {
            return [0, []];
        }

        $gamesById = [];
        foreach ($this->games->findByIds(array_values(array_unique(array_column($slots, 'gameId')))) as $game) {
            $gamesById[$game->getId()] = $game;
        }

        $configurable = 0;
        $missing = [];
        foreach ($slots as $slot) {
            $game = $gamesById[$slot['gameId']] ?? null;
            if (!$game instanceof Game || !$game->isApworldReady()) {
                continue;
            }
            ++$configurable;
            $playerYaml = $slot['playerYaml'] ?? null;
            $defaultYaml = $game->getDefaultYaml();
            if ((null === $playerYaml || '' === $playerYaml) && (null === $defaultYaml || '' === $defaultYaml)) {
                $missing[] = $slot['slotId'];
            }
        }

        return [$configurable, $missing];
    }
}
