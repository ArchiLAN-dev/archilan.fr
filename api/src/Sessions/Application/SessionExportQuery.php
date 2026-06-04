<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\Identity\Domain\UserRepositoryInterface;
use App\Registrations\Domain\Registration;
use App\Registrations\Domain\RegistrationRepositoryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\Sessions\Domain\SessionSlot;
use App\Sessions\Domain\SessionSlotRepositoryInterface;

final readonly class SessionExportQuery
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private SessionSlotRepositoryInterface $slots,
        private RegistrationRepositoryInterface $registrations,
        private UserRepositoryInterface $users,
        private GameRepositoryInterface $games,
    ) {
    }

    /**
     * Returns null if the session is not found.
     *
     * @return list<array{slot_name: string, player: string, game: string, checks_done: int, items_received: int, goal_reached_at: string|null}>|null
     */
    public function findSlotsForSession(string $sessionId): ?array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return null;
        }

        $slotsList = $this->slots->findBySessionId($sessionId);

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

        return $rows;
    }
}
