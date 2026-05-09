<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ExportController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/v1/admin/sessions/{id}/export', methods: ['GET'])]
    public function export(Request $request, string $id): Response
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $session = $this->entityManager->find(Session::class, $id);
        if (!$session instanceof Session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        /** @var list<SessionSlot> $slots */
        $slots = $this->entityManager->getRepository(SessionSlot::class)
            ->findBy(['sessionId' => $id], ['slotOrder' => 'ASC']);

        $rows = $this->buildRows($slots);

        $format = $request->query->get('format', 'json');

        if ('csv' === $format) {
            return $this->csvResponse($rows, $id);
        }

        return new JsonResponse(['data' => $rows]);
    }

    /**
     * @param list<SessionSlot> $slots
     *
     * @return list<array{slot_name: string, player: string, game: string, checks_done: int, items_received: int, goal_reached_at: string|null}>
     */
    private function buildRows(array $slots): array
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

            $rows[] = [
                'slot_name' => $slot->getSlotName(),
                'player' => $user?->getDisplayName() ?? $user?->getEmail() ?? $slot->getRegistrationId(),
                'game' => $game?->getName() ?? $slot->getGameId(),
                'checks_done' => $slot->getChecksDone(),
                'items_received' => $slot->getItemsReceived(),
                'goal_reached_at' => $slot->getGoalReachedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{slot_name: string, player: string, game: string, checks_done: int, items_received: int, goal_reached_at: string|null}> $rows
     */
    private function csvResponse(array $rows, string $sessionId): Response
    {
        $csv = "slot_name,player,game,checks_done,items_received,goal_reached_at\n";
        foreach ($rows as $row) {
            $csv .= sprintf(
                "%s,%s,%s,%d,%d,%s\n",
                $this->csvEscape($row['slot_name']),
                $this->csvEscape($row['player']),
                $this->csvEscape($row['game']),
                $row['checks_done'],
                $row['items_received'],
                $this->csvEscape($row['goal_reached_at'] ?? ''),
            );
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="session-'.$sessionId.'.csv"',
        ]);
    }

    private function csvEscape(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
