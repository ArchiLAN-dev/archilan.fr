<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Events\Domain\Event;
use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SessionResultsController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/v1/events/{eventId}/session/results', methods: ['GET'])]
    public function results(Request $request, string $eventId): JsonResponse
    {
        $event = $this->entityManager->find(Event::class, $eventId);
        if (!$event instanceof Event) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
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
            return new JsonResponse(['data' => null]);
        }

        /** @var list<SessionSlot> $slots */
        $slots = $this->entityManager->getRepository(SessionSlot::class)
            ->findBy(['sessionId' => $session->getId()], ['slotOrder' => 'ASC']);

        $slotRows = $this->buildSlotRows($slots);

        return new JsonResponse([
            'data' => [
                'session' => [
                    'id' => $session->getId(),
                    'status' => $session->getStatus(),
                    'startedAt' => $session->payload()['startedAt'],
                    'finishedAt' => $session->payload()['finishedAt'],
                ],
                'slots' => $slotRows,
            ],
        ]);
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

        $rows = [];
        foreach ($slots as $slot) {
            $reg = $regById[$slot->getRegistrationId()] ?? null;
            $user = null !== $reg ? ($userById[$reg->getUserId()] ?? null) : null;
            $game = $gameById[$slot->getGameId()] ?? null;
            $playerName = $user?->getDisplayName() ?? $user?->getEmail() ?? $slot->getRegistrationId();
            $gameName = $game?->getName() ?? $slot->getGameId();

            $rows[] = [
                'slot_name' => $slot->getSlotName(),
                'player' => $playerName,
                'game' => $gameName,
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
                return strcmp((string) $a['goal_reached_at'], (string) $b['goal_reached_at']);
            }

            return (int) $b['checks_done'] - (int) $a['checks_done'];
        });

        return $rows;
    }
}
