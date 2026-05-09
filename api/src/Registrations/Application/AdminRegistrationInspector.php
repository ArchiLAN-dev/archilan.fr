<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Domain\User;
use App\Payments\Application\HelloAssoPaymentLookup;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminRegistrationInspector
{
    public function __construct(
        private EntityManagerInterface $entityManager,
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
        $registration = $this->entityManager->find(Registration::class, $registrationId);

        if (!$registration instanceof Registration || $registration->getEventId() !== $eventId) {
            return null;
        }

        $event = $this->entityManager->find(Event::class, $eventId);

        if (!$event instanceof Event) {
            return null;
        }

        $user = $this->entityManager->find(User::class, $registration->getUserId());

        $privateAccessCount = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(EventPrivateAccessLog::class, 'l')
            ->where('l.eventId = :eventId')
            ->andWhere('l.userId = :userId')
            ->andWhere('l.granted = :granted')
            ->setParameter('eventId', $eventId)
            ->setParameter('userId', $registration->getUserId())
            ->setParameter('granted', true)
            ->getQuery()
            ->getSingleScalarResult();

        $slots = $registration->getGameSlots();
        $uniqueGameIds = array_values(array_unique(array_column($slots, 'gameId')));

        /** @var array<string, ArchipelagoGame> $gamesById */
        $gamesById = [];
        if ([] !== $uniqueGameIds) {
            /** @var list<ArchipelagoGame> $games */
            $games = $this->entityManager->createQueryBuilder()
                ->select('g')
                ->from(ArchipelagoGame::class, 'g')
                ->where('g.id IN (:ids)')
                ->setParameter('ids', $uniqueGameIds)
                ->getQuery()
                ->getResult();

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
