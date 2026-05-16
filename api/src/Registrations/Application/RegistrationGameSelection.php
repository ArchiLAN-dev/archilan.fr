<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Events\Domain\Event;
use App\GameSelection\Domain\Game;
use App\Identity\Application\ValidationErrors;
use App\Registrations\Domain\Registration;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class RegistrationGameSelection
{
    use EntityFinderTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Returns game selection data for a registration, or null if not accessible.
     *
     * @return array{registrationId: string, eventId: string, gameSelectionEnabled: bool, maxGamesPerRegistrant: int|null, registrationOpen: bool, slots: list<array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null}>, availableGames: list<array<string, mixed>>}|null
     */
    public function getSelection(string $registrationId, string $userId): ?array
    {
        try {
            $registration = $this->findOrFail(Registration::class, $registrationId);
        } catch (\RuntimeException) {
            return null;
        }

        if ($registration->getUserId() !== $userId || !$registration->isReserved()) {
            return null;
        }

        try {
            $event = $this->findOrFail(Event::class, $registration->getEventId());
        } catch (\RuntimeException) {
            return null;
        }

        $registrationOpen = $event->getRegistrationClosesAt() > new \DateTimeImmutable();

        $configuredGameIds = $event->isGameSelectionEnabled()
            ? array_column($event->getGameSelectionConfig(), 'gameId')
            : [];

        $slotGameIds = array_unique(array_column($registration->getGameSlots(), 'gameId'));
        $allGameIds = array_values(array_unique(array_merge($configuredGameIds, $slotGameIds)));

        /** @var array<string, Game> $gamesById */
        $gamesById = [];
        if ([] !== $allGameIds) {
            /** @var list<Game> $games */
            $games = $this->entityManager->getRepository(Game::class)->findBy(['id' => $allGameIds], ['name' => 'ASC']);

            foreach ($games as $game) {
                $gamesById[$game->getId()] = $game;
            }
        }

        $availableGames = [];
        foreach ($configuredGameIds as $gameId) {
            $game = $gamesById[$gameId] ?? null;
            if (null === $game) {
                continue;
            }
            $availableGames[] = [
                'id' => $game->getId(),
                'name' => $game->getName(),
                'slug' => $game->getSlug(),
                'description' => $game->getDescription(),
                'availability' => $game->getAvailability(),
                'isApworldReady' => $game->isApworldReady(),
                'defaultYaml' => $game->getDefaultYaml(),
                'coverImageUrl' => $game->getCoverImageUrl(),
                'coverImageAlt' => $game->getCoverImageAlt(),
            ];
        }

        $slots = [];
        foreach ($registration->getGameSlots() as $slot) {
            $game = $gamesById[$slot['gameId']] ?? null;
            $slots[] = array_merge($slot, [
                'gameName' => $game?->getName() ?? $slot['gameId'],
                'playerYaml' => $slot['playerYaml'] ?? null,
                'apworldHash' => $slot['apworldHash'] ?? null,
            ]);
        }

        return [
            'registrationId' => $registration->getId(),
            'eventId' => $registration->getEventId(),
            'eventTitle' => $event->getTitle(),
            'gameSelectionEnabled' => $event->isGameSelectionEnabled(),
            'maxGamesPerRegistrant' => $event->getGameSelectionMaxPerRegistrant(),
            'registrationOpen' => $registrationOpen,
            'slots' => $slots,
            'availableGames' => $availableGames,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{outcome: 'ok', slots: list<array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null}>}|array{outcome: 'error', errors: array<string, list<string>>}|null
     */
    public function saveSelection(string $registrationId, string $userId, array $input): ?array
    {
        try {
            $registration = $this->findOrFail(Registration::class, $registrationId);
        } catch (\RuntimeException) {
            return null;
        }

        if ($registration->getUserId() !== $userId || !$registration->isReserved()) {
            return null;
        }

        try {
            $event = $this->findOrFail(Event::class, $registration->getEventId());
        } catch (\RuntimeException) {
            return null;
        }

        if (!$event->isGameSelectionEnabled()) {
            return ['outcome' => 'error', 'errors' => ['gameSelection' => ["La sélection de jeux n'est pas activée pour cet événement."]]];
        }

        $now = new \DateTimeImmutable();
        if ($event->getRegistrationClosesAt() <= $now) {
            return ['outcome' => 'error', 'errors' => ['registration' => ["La période d'inscription est terminée."]]];
        }

        $gameIds = [];
        if (is_array($input['gameIds'] ?? null)) {
            foreach ($input['gameIds'] as $id) {
                if (is_string($id)) {
                    $gameIds[] = $id;
                }
            }
        }

        $errors = $this->validateGameIds($gameIds, $event);

        if ([] !== $errors) {
            return ['outcome' => 'error', 'errors' => $errors];
        }

        /** @var array<string, Game> $gamesById */
        $gamesById = [];
        if ([] !== $gameIds) {
            /** @var list<Game> $games */
            $games = $this->entityManager->getRepository(Game::class)->findBy(['id' => array_values(array_unique($gameIds))]);

            foreach ($games as $game) {
                $gamesById[$game->getId()] = $game;
            }
        }

        $diffedSlots = $this->diffSlots($registration->getGameSlots(), $gameIds, $gamesById);
        $registration->replaceSlots($diffedSlots, $now);

        $this->entityManager->flush();

        $this->logger->info('registration.game_selection_saved', ['registrationId' => $registrationId]);

        return ['outcome' => 'ok', 'slots' => $registration->getGameSlots()];
    }

    /**
     * @return array{outcome: 'ok'}|array{outcome: 'error', errors: array<string, list<string>>}|null
     */
    public function saveSlotYaml(string $registrationId, string $userId, string $slotId, string $playerYaml): ?array
    {
        try {
            $registration = $this->findOrFail(Registration::class, $registrationId);
        } catch (\RuntimeException) {
            return null;
        }

        if ($registration->getUserId() !== $userId || !$registration->isReserved()) {
            return null;
        }

        $slot = $registration->getSlot($slotId);

        if (null === $slot) {
            return null;
        }

        try {
            $game = $this->findOrFail(Game::class, $slot['gameId']);
        } catch (\RuntimeException) {
            return null;
        }

        if (!$game->isApworldReady()) {
            return ['outcome' => 'error', 'errors' => ['game' => ["Ce jeu n'a pas encore de fichier .apworld configuré."]]];
        }

        $registration->setSlotPlayerYaml($slotId, $playerYaml, $game->getApworldHash() ?? '', new \DateTimeImmutable());

        $this->entityManager->flush();

        $this->logger->info('registration.slot_yaml_saved', ['registrationId' => $registrationId, 'slotId' => $slotId]);

        return ['outcome' => 'ok'];
    }

    /**
     * @param list<string> $gameIds
     *
     * @return array<string, list<string>>
     */
    private function validateGameIds(array $gameIds, Event $event): array
    {
        $errors = new ValidationErrors();

        $max = $event->getGameSelectionMaxPerRegistrant();
        if (null !== $max && count($gameIds) > $max) {
            $errors->add('gameIds', sprintf('La sélection ne peut pas dépasser %d jeu(x).', $max));

            return $errors->toArray();
        }

        $availableIds = array_column($event->getGameSelectionConfig(), 'gameId');

        foreach ($gameIds as $index => $gameId) {
            if (!in_array($gameId, $availableIds, true)) {
                $errors->add(sprintf('gameIds.%d', $index), "Ce jeu n'est pas disponible pour cet événement.");
            }
        }

        return $errors->toArray();
    }

    /**
     * @param list<array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null}> $existingSlots
     * @param list<string>                                                                                                     $gameIds
     *
     * @return list<array{slotId: string, gameId: string}>
     */
    /**
     * @param list<array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null}> $existingSlots
     * @param list<string>                                                                                                     $gameIds
     * @param array<string, Game>                                                                                              $gamesById
     *
     * @return list<array{slotId: string, gameId: string, playerYaml?: string|null, apworldHash?: string|null}>
     */
    private function diffSlots(array $existingSlots, array $gameIds, array $gamesById = []): array
    {
        /** @var array<string, list<array{slotId: string, playerYaml?: string|null, apworldHash?: string|null}>> $existingByGameId */
        $existingByGameId = [];
        foreach ($existingSlots as $slot) {
            $existingByGameId[$slot['gameId']][] = [
                'slotId' => $slot['slotId'],
                'playerYaml' => $slot['playerYaml'] ?? null,
                'apworldHash' => $slot['apworldHash'] ?? null,
            ];
        }

        $result = [];
        foreach ($gameIds as $gameId) {
            if (!empty($existingByGameId[$gameId])) {
                $matched = array_shift($existingByGameId[$gameId]);
                $result[] = [
                    'slotId' => $matched['slotId'],
                    'gameId' => $gameId,
                    'playerYaml' => $matched['playerYaml'] ?? null,
                    'apworldHash' => $matched['apworldHash'] ?? null,
                ];
            } else {
                $game = $gamesById[$gameId] ?? null;
                $result[] = [
                    'slotId' => bin2hex(random_bytes(8)),
                    'gameId' => $gameId,
                    'playerYaml' => $game?->getDefaultYaml(),
                    'apworldHash' => $game?->getApworldHash(),
                ];
            }
        }

        return $result;
    }
}
