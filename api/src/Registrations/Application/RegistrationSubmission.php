<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Communications\Application\RegistrationConfirmationMessage;
use App\Events\Domain\EventRepositoryInterface;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use App\Registrations\Domain\Registration;
use App\Registrations\Domain\RegistrationRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class RegistrationSubmission
{
    public function __construct(
        private RegistrationRepositoryInterface $registrationRepository,
        private EventRepositoryInterface $eventRepository,
        private UserRepositoryInterface $userRepository,
        private GameRepositoryInterface $gameRepository,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Validates and confirms a registration after the registrant has reviewed their selection.
     * Returns null if the registration does not exist, does not belong to the given user,
     * or is not in reserved status.
     *
     * @return array{outcome: 'confirmed', registrationId: string, eventTitle: string, selectedGameIds: list<string>}|array{outcome: 'error', code: string, message: string}|null
     */
    public function submit(string $registrationId, string $userId): ?array
    {
        $registration = $this->registrationRepository->findById($registrationId);

        if (null === $registration) {
            return null;
        }

        if ($registration->getUserId() !== $userId || !$registration->isReserved()) {
            return null;
        }

        $event = $this->eventRepository->findById($registration->getEventId());

        if (null === $event) {
            return null;
        }

        if ($event->isGameSelectionEnabled()) {
            $gameValidationError = $this->validateGameSelection($registration);

            if (null !== $gameValidationError) {
                return $gameValidationError;
            }
        }

        $alreadyConfirmed = null !== $registration->getSubmittedAt();

        if (!$alreadyConfirmed) {
            $now = new \DateTimeImmutable();
            $registration->confirm($now);
            $this->registrationRepository->flush();

            $this->logger->info('registration.confirmed', ['registrationId' => $registrationId, 'userId' => $userId]);

            $user = $this->userRepository->findById($userId);
            $selectedGameNames = $this->resolveGameNames($registration->getSelectedGameIds());

            $this->messageBus->dispatch(new RegistrationConfirmationMessage(
                userEmail: $user instanceof User ? $user->getEmail() : $userId,
                userDisplayName: $user instanceof User ? $user->getDisplayName() : null,
                eventTitle: $event->getTitle(),
                eventStartsAt: $event->getStartsAt()->format('d/m/Y a H\hi'),
                eventVenue: $event->getVenue(),
                selectedGameNames: $selectedGameNames,
            ));
        }

        return [
            'outcome' => 'confirmed',
            'registrationId' => $registration->getId(),
            'eventTitle' => $event->getTitle(),
            'selectedGameIds' => $registration->getSelectedGameIds(),
        ];
    }

    /**
     * @return array{outcome: 'error', code: string, message: string}|null
     */
    private function validateGameSelection(Registration $registration): ?array
    {
        $slots = $registration->getGameSlots();

        if ([] === $slots) {
            return [
                'outcome' => 'error',
                'code' => 'games_required',
                'message' => 'Tu dois selectionner au moins un jeu avant de confirmer.',
            ];
        }

        return null;
    }

    /**
     * @param list<string> $gameIds ordered list (may contain duplicates)
     *
     * @return list<string>
     */
    private function resolveGameNames(array $gameIds): array
    {
        if ([] === $gameIds) {
            return [];
        }

        /** @var list<string> $uniqueIds */
        $uniqueIds = array_values(array_unique($gameIds));
        $games = $this->gameRepository->findByIds($uniqueIds);

        /** @var array<string, string> $namesById */
        $namesById = [];
        foreach ($games as $game) {
            $namesById[$game->getId()] = $game->getName();
        }

        $names = [];
        foreach ($gameIds as $gameId) {
            $name = $namesById[$gameId] ?? null;
            if (null !== $name && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
