<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SessionExportQuery
{
    use EntityFinderTrait;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Returns null if the session is not found.
     *
     * @return list<array{slot_name: string, player: string, game: string, checks_done: int, items_received: int, goal_reached_at: string|null}>|null
     */
    public function findSlotsForSession(string $sessionId): ?array
    {
        try {
            $session = $this->findOrFail(Session::class, $sessionId);
        } catch (\RuntimeException) {
            return null;
        }

        /** @var list<SessionSlot> $slots */
        $slots = $this->entityManager->getRepository(SessionSlot::class)
            ->findBy(['sessionId' => $sessionId], ['slotOrder' => 'ASC']);

        if ([] === $slots) {
            return [];
        }

        $registrationIds = array_unique(array_map(static fn (SessionSlot $s) => $s->getRegistrationId(), $slots));

        /** @var list<Registration> $registrations */
        $registrations = $this->entityManager->getRepository(Registration::class)->findBy(['id' => $registrationIds]);

        /** @var array<string, Registration> $regById */
        $regById = [];
        foreach ($registrations as $reg) {
            $regById[$reg->getId()] = $reg;
        }

        $userIds = array_unique(array_map(static fn (Registration $r) => $r->getUserId(), $registrations));

        /** @var list<User> $users */
        $users = [] !== $userIds
            ? $this->entityManager->getRepository(User::class)->findBy(['id' => $userIds])
            : [];

        /** @var array<string, User> $userById */
        $userById = [];
        foreach ($users as $user) {
            $userById[$user->getId()] = $user;
        }

        $gameIds = array_unique(array_map(static fn (SessionSlot $s) => $s->getGameId(), $slots));

        /** @var list<Game> $games */
        $games = $this->entityManager->getRepository(Game::class)->findBy(['id' => $gameIds]);

        /** @var array<string, Game> $gameById */
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
}
