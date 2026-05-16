<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\Payments\Application\HelloAssoPaymentLookup;
use App\Registrations\Domain\Registration;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminRegistrationInspector
{
    use EntityFinderTrait;

    private string $privateAccessLogTable;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private HelloAssoPaymentLookup $paymentLookup,
    ) {
        $this->privateAccessLogTable = $entityManager->getClassMetadata(EventPrivateAccessLog::class)->getTableName();
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
        try {
            $registration = $this->findOrFail(Registration::class, $registrationId);
        } catch (\RuntimeException) {
            return null;
        }

        if ($registration->getEventId() !== $eventId) {
            return null;
        }

        try {
            $event = $this->findOrFail(Event::class, $eventId);
        } catch (\RuntimeException) {
            return null;
        }

        $user = $this->entityManager->find(User::class, $registration->getUserId());

        $qb = $this->connection->createQueryBuilder();
        $privateAccessRaw = $qb->select('COUNT(l.id)')
            ->from($this->privateAccessLogTable, 'l')
            ->where($qb->expr()->and(
                $qb->expr()->eq('l.event_id', ':eventId'),
                $qb->expr()->eq('l.user_id', ':userId'),
                $qb->expr()->eq('l.granted', ':granted'),
            ))
            ->setParameter('eventId', $eventId)
            ->setParameter('userId', $registration->getUserId())
            ->setParameter('granted', true)
            ->executeQuery()
            ->fetchOne();

        $privateAccessCount = (false !== $privateAccessRaw && is_numeric($privateAccessRaw)) ? (int) $privateAccessRaw : 0;

        $slots = $registration->getGameSlots();
        $uniqueGameIds = array_values(array_unique(array_column($slots, 'gameId')));

        /** @var array<string, Game> $gamesById */
        $gamesById = [];
        if ([] !== $uniqueGameIds) {
            /** @var list<Game> $games */
            $games = $this->entityManager->getRepository(Game::class)->findBy(['id' => $uniqueGameIds]);

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
