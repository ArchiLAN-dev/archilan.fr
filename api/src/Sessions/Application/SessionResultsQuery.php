<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\Events\Domain\Event;
use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SessionResultsQuery
{
    use EntityFinderTrait;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Returns null if the event is not found.
     * Returns array with session=null if no finished session exists for the event.
     *
     * @return array{
     *     session: array{id: string, status: string, startedAt: mixed, finishedAt: mixed}|null,
     *     slots: list<array<string, mixed>>,
     * }|null
     */
    public function findForEvent(string $eventId): ?array
    {
        try {
            $this->findOrFail(Event::class, $eventId);
        } catch (\RuntimeException) {
            return null;
        }

        /** @var Session|null $session */
        $session = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Session::class, 's')
            ->where('s.eventId = :eventId AND s.status = :status')
            ->setParameter('eventId', $eventId)
            ->setParameter('status', Session::STATUS_FINISHED)
            ->orderBy('s.finishedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$session instanceof Session) {
            return ['session' => null, 'slots' => []];
        }

        /** @var list<SessionSlot> $slots */
        $slots = $this->entityManager->getRepository(SessionSlot::class)
            ->findBy(['sessionId' => $session->getId()], ['slotOrder' => 'ASC']);

        return [
            'session' => [
                'id' => $session->getId(),
                'status' => $session->getStatus(),
                'startedAt' => $session->payload()['startedAt'],
                'finishedAt' => $session->payload()['finishedAt'],
            ],
            'slots' => $this->buildSlotRows($slots),
        ];
    }

    /**
     * @param list<SessionSlot> $slots
     *
     * @return list<array<string, mixed>>
     */
    private function buildSlotRows(array $slots): array
    {
        if ([] === $slots) {
            return [];
        }

        $registrationIds = array_unique(array_map(static fn (SessionSlot $s) => $s->getRegistrationId(), $slots));

        /** @var list<Registration> $registrations */
        $registrations = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(Registration::class, 'r')
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $registrationIds)
            ->getQuery()
            ->getResult();

        /** @var array<string, Registration> $regById */
        $regById = [];
        foreach ($registrations as $reg) {
            $regById[$reg->getId()] = $reg;
        }

        $userIds = array_unique(array_map(static fn (Registration $r) => $r->getUserId(), $registrations));

        /** @var list<User> $users */
        $users = [] !== $userIds
            ? $this->entityManager->createQueryBuilder()
                ->select('u')
                ->from(User::class, 'u')
                ->where('u.id IN (:ids)')
                ->setParameter('ids', $userIds)
                ->getQuery()
                ->getResult()
            : [];

        /** @var array<string, User> $userById */
        $userById = [];
        foreach ($users as $user) {
            $userById[$user->getId()] = $user;
        }

        $gameIds = array_unique(array_map(static fn (SessionSlot $s) => $s->getGameId(), $slots));

        /** @var list<ArchipelagoGame> $games */
        $games = $this->entityManager->createQueryBuilder()
            ->select('g')
            ->from(ArchipelagoGame::class, 'g')
            ->where('g.id IN (:ids)')
            ->setParameter('ids', $gameIds)
            ->getQuery()
            ->getResult();

        /** @var array<string, ArchipelagoGame> $gameById */
        $gameById = [];
        foreach ($games as $game) {
            $gameById[$game->getId()] = $game;
        }

        /** @var list<array{slot_name: string, player: string, game: string, checks_done: int, items_received: int, goal_reached_at: string|null}> $rows */
        $rows = [];
        foreach ($slots as $slot) {
            $reg = $regById[$slot->getRegistrationId()] ?? null;
            $user = null !== $reg ? ($userById[$reg->getUserId()] ?? null) : null;
            $game = $gameById[$slot->getGameId()] ?? null;

            $rows[] = [
                'slot_name' => $slot->getSlotName(),
                'player' => $user?->getDisplayName() ?? $user?->getEmail() ?? $slot->getRegistrationId(),
                'game' => $game?->getName() ?? $slot->getGameId(),
                'checks_done' => $slot->getChecksDone(),
                'items_received' => $slot->getItemsReceived(),
                'goal_reached_at' => $slot->getGoalReachedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $aGoal = null !== $a['goal_reached_at'];
            $bGoal = null !== $b['goal_reached_at'];

            if ($aGoal && !$bGoal) {
                return -1;
            }
            if (!$aGoal && $bGoal) {
                return 1;
            }
            if ($aGoal) {
                return strcmp($a['goal_reached_at'] ?? '', $b['goal_reached_at'] ?? '');
            }

            return $b['checks_done'] - $a['checks_done'];
        });

        return $rows;
    }
}
