<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Events\Domain\EventRepositoryInterface;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\Identity\Domain\UserRepositoryInterface;
use App\Payments\Application\HelloAssoPaymentLookup;
use App\Registrations\Domain\RegistrationRepositoryInterface;

final readonly class AdminRegistrationInspector
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
     * Returns full registration detail for admin inspection, or null if not found.
     *
     * @return array{
     *   registrationId: string,
     *   status: string,
     *   usedPrivateAccess: bool,
     *   createdAt: string,
     *   submittedAt: string|null,
     *   participant: array{userId: string, displayName: string|null, email: string},
     *   gameSelectionComplete: bool,
     *   games: list<array{
     *     slotId: string,
     *     slotOrder: int,
     *     gameId: string,
     *     gameName: string,
     *     isComplete: bool,
     *     warnings: list<string>,
     *     playerYaml: string|null
     *   }>,
     *   payment: array{status: string, amountCents: int, syncedAt: string, isStale: bool}|null
     * }|null
     */
    public function inspect(string $eventId, string $registrationId): ?array
    {
        $registration = $this->registrationRepository->findById($registrationId);

        if (null === $registration) {
            return null;
        }

        if ($registration->getEventId() !== $eventId) {
            return null;
        }

        $event = $this->eventRepository->findById($eventId);

        if (null === $event) {
            return null;
        }

        $user = $this->userRepository->findById($registration->getUserId());

        $privateAccessCount = $this->privateAccessGrantedQuery->countGrantedForUser($eventId, $registration->getUserId());

        $slots = $registration->getGameSlots();
        /** @var list<string> $uniqueGameIds */
        $uniqueGameIds = array_values(array_unique(array_column($slots, 'gameId')));

        /** @var array<string, Game> $gamesById */
        $gamesById = [];
        if ([] !== $uniqueGameIds) {
            $games = $this->gameRepository->findByIds($uniqueGameIds);

            foreach ($games as $game) {
                $gamesById[$game->getId()] = $game;
            }
        }

        $gameDetails = [];
        $overallComplete = [] !== $slots;

        foreach ($slots as $slot) {
            $game = $gamesById[$slot['gameId']] ?? null;
            $warnings = [];
            $gameComplete = true;
            $playerYaml = $slot['playerYaml'] ?? null;

            if (null === $game) {
                $gameComplete = false;
                $warnings[] = 'Le jeu sélectionné n\'existe plus dans la bibliothèque.';
            } elseif ($game->isApworldReady()) {
                if (null === $playerYaml || '' === $playerYaml) {
                    $gameComplete = false;
                }
            }

            if (!$gameComplete) {
                $overallComplete = false;
            }

            $gameDetails[] = [
                'slotId' => $slot['slotId'],
                'slotOrder' => $slot['slotOrder'],
                'gameId' => $slot['gameId'],
                'gameName' => $game?->getName() ?? $slot['gameId'],
                'isComplete' => $gameComplete,
                'warnings' => $warnings,
                'playerYaml' => $playerYaml,
            ];
        }

        $email = $user?->getEmail() ?? '';

        return [
            'registrationId' => $registration->getId(),
            'status' => $registration->getStatus(),
            'usedPrivateAccess' => $privateAccessCount > 0,
            'createdAt' => $registration->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'submittedAt' => $registration->getSubmittedAt()?->format(\DateTimeInterface::ATOM),
            'participant' => [
                'userId' => $registration->getUserId(),
                'displayName' => $user?->getDisplayName(),
                'email' => $email,
            ],
            'gameSelectionComplete' => $overallComplete,
            'games' => $gameDetails,
            'payment' => '' !== $email ? $this->paymentLookup->findForEventAndEmail($eventId, $email) : null,
        ];
    }
}
