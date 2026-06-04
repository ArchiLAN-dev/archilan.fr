<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Events\Domain\EventRepositoryInterface;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\Identity\Domain\UserRepositoryInterface;
use App\Payments\Application\HelloAssoPaymentLookup;
use App\Registrations\Domain\Registration;
use App\Registrations\Domain\RegistrationRepositoryInterface;

final readonly class AdminRegistrationDashboard
{
    public function __construct(
        private RegistrationRepositoryInterface $registrationRepository,
        private EventRepositoryInterface $eventRepository,
        private UserRepositoryInterface $userRepository,
        private GameRepositoryInterface $gameRepository,
        private PrivateAccessGrantedQueryInterface $privateAccessGrantedQuery,
        private HelloAssoPaymentLookup $paymentLookup,
    ) {
    }

    /**
     * Lists registrations for an event. Returns null if the event does not exist.
     *
     * @return list<array{
     *   registrationId: string,
     *   status: string,
     *   usedPrivateAccess: bool,
     *   createdAt: string,
     *   submittedAt: string|null,
     *   participant: array{userId: string, displayName: string|null, email: string},
     *   selectedGames: list<array{gameId: string, gameName: string}>,
     *   gameSelectionComplete: bool,
     *   payment: array{status: string, amountCents: int, syncedAt: string, isStale: bool}|null
     * }>|null
     */
    public function list(string $eventId, ?string $statusFilter): ?array
    {
        $event = $this->eventRepository->findById($eventId);

        if (null === $event) {
            return null;
        }

        $criteria = ['eventId' => $eventId];
        if (null !== $statusFilter && in_array($statusFilter, [Registration::STATUS_RESERVED, Registration::STATUS_CANCELLED], true)) {
            $criteria['status'] = $statusFilter;
        }

        $registrations = $this->registrationRepository->findBy($criteria, ['createdAt' => 'DESC']);

        if ([] === $registrations) {
            return [];
        }

        /** @var list<string> $userIds */
        $userIds = array_values(array_unique(array_map(static fn (Registration $r): string => $r->getUserId(), $registrations)));

        $users = $this->userRepository->findByIds($userIds);

        /** @var array<string, \App\Identity\Domain\User> $usersById */
        $usersById = [];
        foreach ($users as $user) {
            $usersById[$user->getId()] = $user;
        }

        $privateAccessUserIds = $this->privateAccessGrantedQuery->findGrantedUserIds($eventId);

        /** @var array<string, true> $privateAccessSet */
        $privateAccessSet = array_fill_keys($privateAccessUserIds, true);

        $gameSelectionConfig = $event->getGameSelectionConfig();
        $allSelectedGameIds = [];
        foreach ($registrations as $registration) {
            foreach ($registration->getSelectedGameIds() as $gameId) {
                $allSelectedGameIds[$gameId] = true;
            }
        }
        /** @var list<string> $allSelectedGameIdsList */
        $allSelectedGameIdsList = array_keys($allSelectedGameIds);

        /** @var array<string, Game> $gamesById */
        $gamesById = [];
        if ([] !== $allSelectedGameIdsList) {
            $games = $this->gameRepository->findByIds($allSelectedGameIdsList);

            foreach ($games as $game) {
                $gamesById[$game->getId()] = $game;
            }
        }

        $result = [];
        foreach ($registrations as $registration) {
            $user = $usersById[$registration->getUserId()] ?? null;
            $email = $user?->getEmail() ?? '';
            $selectedGames = $this->buildSelectedGamesSummary($registration, $gamesById);
            $result[] = [
                'registrationId' => $registration->getId(),
                'status' => $registration->getStatus(),
                'usedPrivateAccess' => isset($privateAccessSet[$registration->getUserId()]),
                'createdAt' => $registration->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'submittedAt' => $registration->getSubmittedAt()?->format(\DateTimeInterface::ATOM),
                'participant' => [
                    'userId' => $registration->getUserId(),
                    'displayName' => $user?->getDisplayName(),
                    'email' => $email,
                ],
                'selectedGames' => $selectedGames,
                'gameSelectionComplete' => $this->isGameSelectionComplete(
                    $registration,
                    $gameSelectionConfig,
                    $gamesById,
                ),
                'payment' => '' !== $email ? $this->paymentLookup->findForEventAndEmail($eventId, $email) : null,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, Game> $gamesById
     *
     * @return list<array{gameId: string, gameName: string}>
     */
    private function buildSelectedGamesSummary(Registration $registration, array $gamesById): array
    {
        $summary = [];
        foreach ($registration->getGameSlots() as $slot) {
            $game = $gamesById[$slot['gameId']] ?? null;
            $summary[] = [
                'gameId' => $slot['gameId'],
                'gameName' => $game?->getName() ?? $slot['gameId'],
            ];
        }

        return $summary;
    }

    /**
     * @param list<array{gameId: string}> $gameSelectionConfig
     * @param array<string, Game>         $gamesById
     */
    private function isGameSelectionComplete(
        Registration $registration,
        array $gameSelectionConfig,
        array $gamesById,
    ): bool {
        $slots = $registration->getGameSlots();

        if ([] === $slots) {
            return false;
        }

        foreach ($slots as $slot) {
            $game = $gamesById[$slot['gameId']] ?? null;
            if (null === $game) {
                return false;
            }

            if (!$game->isApworldReady()) {
                continue;
            }

            $playerYaml = $slot['playerYaml'] ?? null;
            if (null === $playerYaml || '' === $playerYaml) {
                return false;
            }
        }

        return true;
    }
}
