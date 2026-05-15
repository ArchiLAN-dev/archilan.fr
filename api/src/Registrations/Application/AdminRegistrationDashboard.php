<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Domain\User;
use App\Payments\Application\HelloAssoPaymentLookup;
use App\Registrations\Domain\Registration;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminRegistrationDashboard
{
    use EntityFinderTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
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
        try {
            $event = $this->findOrFail(Event::class, $eventId);
        } catch (\RuntimeException) {
            return null;
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(Registration::class, 'r')
            ->where('r.eventId = :eventId')
            ->setParameter('eventId', $eventId)
            ->orderBy('r.createdAt', 'DESC');

        if (null !== $statusFilter && in_array($statusFilter, [Registration::STATUS_RESERVED, Registration::STATUS_CANCELLED], true)) {
            $qb->andWhere('r.status = :status')->setParameter('status', $statusFilter);
        }

        /** @var list<Registration> $registrations */
        $registrations = $qb->getQuery()->getResult();

        if ([] === $registrations) {
            return [];
        }

        /** @var list<string> $userIds */
        $userIds = array_unique(array_map(static fn (Registration $r): string => $r->getUserId(), $registrations));

        /** @var list<User> $users */
        $users = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $userIds)
            ->getQuery()
            ->getResult();

        /** @var array<string, User> $usersById */
        $usersById = [];
        foreach ($users as $user) {
            $usersById[$user->getId()] = $user;
        }

        /** @var list<string> $privateAccessUserIds */
        $privateAccessUserIds = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT l.userId')
            ->from(EventPrivateAccessLog::class, 'l')
            ->where('l.eventId = :eventId')
            ->andWhere('l.granted = :granted')
            ->setParameter('eventId', $eventId)
            ->setParameter('granted', true)
            ->getQuery()
            ->getSingleColumnResult();

        /** @var array<string, true> $privateAccessSet */
        $privateAccessSet = array_fill_keys($privateAccessUserIds, true);

        $gameSelectionConfig = $event->getGameSelectionConfig();
        $allSelectedGameIds = [];
        foreach ($registrations as $registration) {
            foreach ($registration->getSelectedGameIds() as $gameId) {
                $allSelectedGameIds[$gameId] = true;
            }
        }
        /** @var list<string> $allSelectedGameIds */
        $allSelectedGameIds = array_keys($allSelectedGameIds);

        /** @var array<string, ArchipelagoGame> $gamesById */
        $gamesById = [];
        if ([] !== $allSelectedGameIds) {
            /** @var list<ArchipelagoGame> $games */
            $games = $this->entityManager->createQueryBuilder()
                ->select('g')
                ->from(ArchipelagoGame::class, 'g')
                ->where('g.id IN (:ids)')
                ->setParameter('ids', $allSelectedGameIds)
                ->getQuery()
                ->getResult();

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
     * @param array<string, ArchipelagoGame> $gamesById
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
     * @param list<array{gameId: string}>    $gameSelectionConfig
     * @param array<string, ArchipelagoGame> $gamesById
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
