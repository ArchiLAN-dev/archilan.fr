<?php

declare(strict_types=1);

namespace App\Sessions\Application\Service;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Domain\Entity\BalanceReport;

/**
 * Story 56.3: what a dry generation of an event or a private run is made of, read the way the real generation
 * reads it: each reserved registration's (or participant's) slots, the saved YAML or else the game's default, the
 * game's current apworld.
 *
 * The fingerprint sums the inputs up: a YAML saved, a game switching apworld, a slot or a registration added or
 * removed all change it, which is how the report knows it is to redo.
 */
final readonly class BalanceInputs
{
    public function __construct(
        private RegistrationRepositoryInterface $registrations,
        private RunParticipantRepositoryInterface $participants,
        private UserRepositoryInterface $users,
        private GameRepositoryInterface $games,
    ) {
    }

    /**
     * @return array{slots: list<array{userId: string, gameId: string, playerName: string, gameName: string, archipelagoGameName: string, playerYaml: string, apworldHash: string}>, games: list<Game>}
     */
    public function forScope(string $scopeType, string $scopeId): array
    {
        $raw = [];
        if (BalanceReport::SCOPE_EVENT === $scopeType) {
            /** @var list<Registration> $registrations */
            $registrations = $this->registrations->findBy(['eventId' => $scopeId, 'status' => Registration::STATUS_RESERVED], ['createdAt' => 'ASC']);
            foreach ($registrations as $registration) {
                foreach ($registration->getGameSlots() as $slot) {
                    $raw[] = ['userId' => $registration->getUserId(), 'slotId' => $slot['slotId'], 'gameId' => $slot['gameId'], 'playerYaml' => is_string($slot['playerYaml'] ?? null) ? $slot['playerYaml'] : ''];
                }
            }
        } else {
            foreach ($this->participants->findByRunId($scopeId) as $participant) {
                foreach ($participant->getGameSlots() as $slot) {
                    $raw[] = ['userId' => $participant->getUserId(), 'slotId' => $slot['slotId'], 'gameId' => $slot['gameId'], 'playerYaml' => $slot['playerYaml'] ?? ''];
                }
            }
        }
        if ([] === $raw) {
            return ['slots' => [], 'games' => []];
        }

        $usersById = [];
        foreach ($this->users->findByIds(array_values(array_unique(array_column($raw, 'userId')))) as $user) {
            $usersById[$user->getId()] = $user;
        }
        $games = $this->games->findByIds(array_values(array_unique(array_column($raw, 'gameId'))));
        $gamesById = [];
        foreach ($games as $game) {
            $gamesById[$game->getId()] = $game;
        }

        $slots = [];
        foreach ($raw as $slot) {
            $user = $usersById[$slot['userId']] ?? null;
            $game = $gamesById[$slot['gameId']] ?? null;
            $slots[] = [
                'userId' => $slot['userId'],
                'gameId' => $slot['gameId'],
                'playerName' => $user?->getDisplayName() ?? $user?->getEmail() ?? $slot['userId'],
                'gameName' => $game?->getName() ?? $slot['gameId'],
                'archipelagoGameName' => $game?->getArchipelagoGameName() ?? '',
                // No saved YAML: the player kept the defaults, as the real generation reads it.
                'playerYaml' => '' !== $slot['playerYaml'] ? $slot['playerYaml'] : ($game?->getDefaultYaml() ?? ''),
                'apworldHash' => $game?->getApworldHash() ?? '',
            ];
        }

        return ['slots' => $slots, 'games' => $games];
    }

    /**
     * @param list<array{userId: string, gameId: string, playerYaml: string, apworldHash: string}> $slots
     */
    public static function fingerprint(array $slots): string
    {
        $parts = array_map(
            static fn (array $s): string => $s['userId'].'|'.$s['gameId'].'|'.$s['apworldHash'].'|'.sha1($s['playerYaml']),
            $slots,
        );
        sort($parts);

        return hash('sha256', implode("\n", $parts));
    }
}
