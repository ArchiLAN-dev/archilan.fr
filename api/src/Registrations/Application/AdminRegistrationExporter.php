<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminRegistrationExporter
{
    use EntityFinderTrait;

    private string $privateAccessLogTable;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
        $this->privateAccessLogTable = $entityManager->getClassMetadata(EventPrivateAccessLog::class)->getTableName();
    }

    /**
     * Builds a JSON-serializable export payload, or null if the event does not exist.
     *
     * @return array{
     *   eventId: string,
     *   eventTitle: string,
     *   exportedAt: string,
     *   includeCancelled: bool,
     *   slots: list<array{
     *     registrationId: string,
     *     status: string,
     *     participant: array{userId: string, displayName: string|null, email: string},
     *     usedPrivateAccess: bool,
     *     createdAt: string,
     *     submittedAt: string|null,
     *     gameSelectionComplete: bool,
     *     slotId: string,
     *     slotOrder: int,
     *     gameId: string,
     *     gameName: string,
     *     playerYaml: string|null
     *   }>,
     *   registrations: list<array{
     *     registrationId: string,
     *     status: string,
     *     participant: array{userId: string, displayName: string|null, email: string},
     *     usedPrivateAccess: bool,
     *     createdAt: string,
     *     submittedAt: string|null,
     *     gameSelectionComplete: bool,
     *     games: list<array{
     *       slotId: string,
     *       slotOrder: int,
     *       gameId: string,
     *       gameName: string,
     *       playerYaml: string|null
     *     }>
     *   }>
     * }|null
     */
    public function export(string $eventId, bool $includeCancelled): ?array
    {
        try {
            $event = $this->findOrFail(Event::class, $eventId);
        } catch (\RuntimeException) {
            return null;
        }

        $criteria = ['eventId' => $eventId];
        if (!$includeCancelled) {
            $criteria['status'] = Registration::STATUS_RESERVED;
        }

        /** @var list<Registration> $registrations */
        $registrations = $this->entityManager->getRepository(Registration::class)->findBy(
            $criteria,
            ['createdAt' => 'ASC'],
        );

        if ([] === $registrations) {
            return $this->emptyExport($event, $includeCancelled);
        }

        /** @var list<string> $userIds */
        $userIds = array_unique(array_map(static fn (Registration $r): string => $r->getUserId(), $registrations));

        /** @var list<User> $users */
        $users = $this->entityManager->getRepository(User::class)->findBy(['id' => $userIds]);

        /** @var array<string, User> $usersById */
        $usersById = [];
        foreach ($users as $user) {
            $usersById[$user->getId()] = $user;
        }

        $qb = $this->connection->createQueryBuilder();
        $rawUserIds = $qb->select('DISTINCT l.user_id')
            ->from($this->privateAccessLogTable, 'l')
            ->where($qb->expr()->and(
                $qb->expr()->eq('l.event_id', ':eventId'),
                $qb->expr()->eq('l.granted', ':granted'),
            ))
            ->setParameter('eventId', $eventId)
            ->setParameter('granted', true)
            ->executeQuery()
            ->fetchFirstColumn();

        /** @var list<string> $privateAccessUserIds */
        $privateAccessUserIds = array_values(array_filter($rawUserIds, 'is_string'));

        /** @var array<string, true> $privateAccessSet */
        $privateAccessSet = array_fill_keys($privateAccessUserIds, true);

        $allSelectedGameIds = [];
        foreach ($registrations as $registration) {
            foreach ($registration->getSelectedGameIds() as $gameId) {
                $allSelectedGameIds[$gameId] = true;
            }
        }
        /** @var list<string> $allSelectedGameIds */
        $allSelectedGameIds = array_keys($allSelectedGameIds);

        /** @var array<string, Game> $gamesById */
        $gamesById = [];
        if ([] !== $allSelectedGameIds) {
            /** @var list<Game> $games */
            $games = $this->entityManager->getRepository(Game::class)->findBy(['id' => $allSelectedGameIds]);

            foreach ($games as $game) {
                $gamesById[$game->getId()] = $game;
            }
        }

        $registrationRows = [];
        $slotRows = [];
        foreach ($registrations as $registration) {
            $user = $usersById[$registration->getUserId()] ?? null;
            $participant = [
                'userId' => $registration->getUserId(),
                'displayName' => $user?->getDisplayName(),
                'email' => $user?->getEmail() ?? '',
            ];
            $usedPrivateAccess = isset($privateAccessSet[$registration->getUserId()]);
            $createdAt = $registration->getCreatedAt()->format(\DateTimeInterface::ATOM);
            $submittedAt = $registration->getSubmittedAt()?->format(\DateTimeInterface::ATOM);
            $gameSelectionComplete = $this->isGameSelectionComplete($registration, $gamesById);

            $games = [];
            foreach ($registration->getGameSlots() as $slot) {
                $game = $gamesById[$slot['gameId']] ?? null;
                $gameRow = [
                    'slotId' => $slot['slotId'],
                    'slotOrder' => $slot['slotOrder'],
                    'gameId' => $slot['gameId'],
                    'gameName' => $game?->getName() ?? $slot['gameId'],
                    'playerYaml' => $slot['playerYaml'] ?? null,
                ];
                $games[] = $gameRow;
                $slotRows[] = [
                    'registrationId' => $registration->getId(),
                    'status' => $registration->getStatus(),
                    'participant' => $participant,
                    'usedPrivateAccess' => $usedPrivateAccess,
                    'createdAt' => $createdAt,
                    'submittedAt' => $submittedAt,
                    'gameSelectionComplete' => $gameSelectionComplete,
                    ...$gameRow,
                ];
            }

            $registrationRows[] = [
                'registrationId' => $registration->getId(),
                'status' => $registration->getStatus(),
                'participant' => $participant,
                'usedPrivateAccess' => $usedPrivateAccess,
                'createdAt' => $createdAt,
                'submittedAt' => $submittedAt,
                'gameSelectionComplete' => $gameSelectionComplete,
                'games' => $games,
            ];
        }

        return [
            'eventId' => $eventId,
            'eventTitle' => $event->getTitle(),
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'includeCancelled' => $includeCancelled,
            'slots' => $slotRows,
            'registrations' => $registrationRows,
        ];
    }

    /**
     * @param array<string, Game> $gamesById
     */
    private function isGameSelectionComplete(
        Registration $registration,
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

    /**
     * @return array{
     *   eventId: string,
     *   eventTitle: string,
     *   exportedAt: string,
     *   includeCancelled: bool,
     *   slots: list<never>,
     *   registrations: list<never>
     * }
     */
    private function emptyExport(Event $event, bool $includeCancelled): array
    {
        return [
            'eventId' => $event->getId(),
            'eventTitle' => $event->getTitle(),
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'includeCancelled' => $includeCancelled,
            'slots' => [],
            'registrations' => [],
        ];
    }
}
