<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\Events\Domain\Event;
use App\Events\Domain\EventRepositoryInterface;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\Identity\Domain\UserRepositoryInterface;
use App\Registrations\Domain\Registration;
use App\Registrations\Domain\RegistrationRepositoryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\Sessions\Domain\SessionSlot;
use App\Sessions\Domain\SessionSlotRepositoryInterface;

final readonly class SessionResultsQuery
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private EventRepositoryInterface $events,
        private SessionSlotRepositoryInterface $slots,
        private RegistrationRepositoryInterface $registrations,
        private UserRepositoryInterface $users,
        private GameRepositoryInterface $games,
    ) {
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
        $event = $this->events->findById($eventId);
        if (!$event instanceof Event) {
            return null;
        }

        $session = $this->sessions->findMostRecentFinishedByEventId($eventId);

        if (!$session instanceof Session) {
            return ['session' => null, 'slots' => []];
        }

        $slotsList = $this->slots->findBySessionId($session->getId());

        return [
            'session' => [
                'id' => $session->getId(),
                'status' => $session->getStatus(),
                'startedAt' => $session->payload()['startedAt'],
                'finishedAt' => $session->payload()['finishedAt'],
            ],
            'slots' => $this->buildSlotRows($slotsList),
        ];
    }

    /**
     * @param list<SessionSlot> $slotsList
     *
     * @return list<array<string, mixed>>
     */
    private function buildSlotRows(array $slotsList): array
    {
        if ([] === $slotsList) {
            return [];
        }

        $registrationIds = array_values(array_unique(array_map(static fn (SessionSlot $s) => $s->getRegistrationId(), $slotsList)));

        /** @var list<Registration> $regList */
        $regList = $this->registrations->findBy(['id' => $registrationIds]);

        /** @var array<string, Registration> $regById */
        $regById = [];
        foreach ($regList as $reg) {
            $regById[$reg->getId()] = $reg;
        }

        $userIds = array_values(array_unique(array_map(static fn (Registration $r) => $r->getUserId(), $regList)));

        $usersList = [] !== $userIds ? $this->users->findByIds($userIds) : [];

        /** @var array<string, \App\Identity\Domain\User> $userById */
        $userById = [];
        foreach ($usersList as $user) {
            $userById[$user->getId()] = $user;
        }

        $gameIds = array_values(array_unique(array_map(static fn (SessionSlot $s) => $s->getGameId(), $slotsList)));

        $gamesList = $this->games->findByIds($gameIds);

        /** @var array<string, Game> $gameById */
        $gameById = [];
        foreach ($gamesList as $game) {
            $gameById[$game->getId()] = $game;
        }

        /** @var list<array{slot_name: string, player: string, game: string, checks_done: int, items_received: int, goal_reached_at: string|null}> $rows */
        $rows = [];
        foreach ($slotsList as $slot) {
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
