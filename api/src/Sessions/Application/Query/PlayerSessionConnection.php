<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Application\Support\SlotsPlayedBy;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Shared\Application\Support\ArchipelagoConnectionUri;

final readonly class PlayerSessionConnection
{
    public function __construct(
        private RegistrationRepositoryInterface $registrations,
        private PlayerConnectionQueryInterface $playerConnectionQuery,
        private SessionRepositoryInterface $sessions,
        private SlotsPlayedBy $playedSlots,
        private GameRepositoryInterface $games,
    ) {
    }

    /**
     * Returns the session connection details for a confirmed registrant, or null if access is denied.
     *
     * Access is granted only when:
     *   - the registration exists and belongs to the given user
     *   - the registration is confirmed (reserved + submittedAt set)
     *
     * The session is reduced to what a player needs to connect: its address only while it runs (story 17.26), the
     * client address built like the other connection views (`connectionUri`, story 37.4), and the slot password. The
     * admin and server passwords, logs, runner and archive paths stay on the admin side.
     *
     * @return array{eventId: string, session: array{id: string, eventId: string, status: string, host: string|null, port: int|null, connectionUri: string|null, password: string|null}|null, slots: list<array{slotName: string, slotOrder: int, gameId: string, gameName: string, gameSlug: string|null, slotId: string|null, coPlayed: bool}>}|null
     */
    public function getConnection(string $registrationId, string $userId): ?array
    {
        $registration = $this->registrations->findById($registrationId);
        if (!$registration instanceof Registration) {
            return null;
        }

        if ($registration->getUserId() !== $userId
            || !$registration->isReserved()
            || null === $registration->getSubmittedAt()
        ) {
            return null;
        }

        $sessionId = $this->playerConnectionQuery->findLatestSessionIdForPlayer($registration->getEventId(), $registrationId, $userId);

        if (null === $sessionId) {
            return ['eventId' => $registration->getEventId(), 'session' => null, 'slots' => []];
        }

        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['eventId' => $registration->getEventId(), 'session' => null, 'slots' => []];
        }

        // Owned slots plus the ones this member was added to as a co-player (story 16.17): a shared
        // slot has to appear in the connection details of everyone who plays it.
        $sessionSlots = $this->playedSlots->inSession($session->getId(), $registrationId, $userId);

        $gameIds = array_values(array_unique(
            array_map(static fn (SessionSlot $s) => $s->getGameId(), $sessionSlots),
        ));

        $foundGames = $this->games->findByIds($gameIds);

        /** @var array<string, string> $nameById */
        $nameById = [];
        /** @var array<string, string> $slugById */
        $slugById = [];
        foreach ($foundGames as $game) {
            $nameById[$game->getId()] = $game->getName();
            $slugById[$game->getId()] = $game->getSlug();
        }

        $slots = array_map(
            static fn (SessionSlot $s): array => [
                'slotName' => $s->getSlotName(),
                'slotOrder' => $s->getSlotOrder(),
                'gameId' => $s->getGameId(),
                'gameName' => $nameById[$s->getGameId()] ?? $s->getGameId(),
                // Story 54.7: the game's page, for its tracker.
                'gameSlug' => $slugById[$s->getGameId()] ?? null,
                // Story 11.10: the game slot id (for its co-players) and whether the member plays it on someone
                // else's registration.
                'slotId' => $s->getSlotId(),
                'coPlayed' => $s->getRegistrationId() !== $registrationId,
            ],
            $sessionSlots,
        );

        return [
            // Story 11.10: the event, for the co-players of each slot.
            'eventId' => $registration->getEventId(),
            'session' => $this->playerView($session),
            'slots' => $slots,
        ];
    }

    /**
     * @return array{id: string, eventId: string, status: string, host: string|null, port: int|null, connectionUri: string|null, password: string|null}
     */
    private function playerView(Session $session): array
    {
        $endpoint = $session->liveEndpoint();

        return [
            'id' => $session->getId(),
            'eventId' => $session->getEventId(),
            'status' => $session->getStatus(),
            'host' => $endpoint['host'] ?? null,
            'port' => $endpoint['port'] ?? null,
            'connectionUri' => ArchipelagoConnectionUri::tryBuild($endpoint['host'] ?? null, $endpoint['port'] ?? null),
            'password' => $session->getPassword(),
        ];
    }
}
