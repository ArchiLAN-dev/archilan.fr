<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\GameSelection\Domain\GameRepositoryInterface;
use App\Registrations\Domain\Registration;
use App\Registrations\Domain\RegistrationRepositoryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\Sessions\Domain\SessionSlot;
use App\Sessions\Domain\SessionSlotRepositoryInterface;

final readonly class PlayerSessionConnection
{
    public function __construct(
        private RegistrationRepositoryInterface $registrations,
        private PlayerConnectionQueryInterface $playerConnectionQuery,
        private SessionRepositoryInterface $sessions,
        private SessionSlotRepositoryInterface $slots,
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
     * @return array{session: array<string, mixed>|null, slots: list<array{slotName: string, slotOrder: int, gameId: string, gameName: string}>}|null
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

        $sessionId = $this->playerConnectionQuery->findLatestSessionIdByRegistrationId($registrationId);

        if (null === $sessionId) {
            return ['session' => null, 'slots' => []];
        }

        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['session' => null, 'slots' => []];
        }

        $sessionSlots = $this->slots->findByRegistrationAndSession($registrationId, $session->getId());

        $gameIds = array_values(array_unique(
            array_map(static fn (SessionSlot $s) => $s->getGameId(), $sessionSlots),
        ));

        $foundGames = $this->games->findByIds($gameIds);

        /** @var array<string, string> $nameById */
        $nameById = [];
        foreach ($foundGames as $game) {
            $nameById[$game->getId()] = $game->getName();
        }

        $slots = array_map(
            static fn (SessionSlot $s): array => [
                'slotName' => $s->getSlotName(),
                'slotOrder' => $s->getSlotOrder(),
                'gameId' => $s->getGameId(),
                'gameName' => $nameById[$s->getGameId()] ?? $s->getGameId(),
            ],
            $sessionSlots,
        );

        return [
            'session' => $session->payload(),
            'slots' => $slots,
        ];
    }
}
