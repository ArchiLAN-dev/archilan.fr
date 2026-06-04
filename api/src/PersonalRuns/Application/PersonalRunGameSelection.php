<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\Identity\Application\ValidationErrors;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipant;
use App\PersonalRuns\Domain\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class PersonalRunGameSelection
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private GameRepositoryInterface $games,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, slots: list<array<string, mixed>>|null, availableGames: list<array<string, mixed>>|null}
     */
    public function getMySlots(string $runId, string $userId): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return $this->result(found: false);
        }

        $participant = $this->loadParticipant($run, $userId);
        if (null === $participant) {
            return $this->result(found: true, authorized: false);
        }

        $existingSlots = $participant->getGameSlots();

        $allGames = $this->games->findByAvailabilitiesSortedByName([
            Game::AVAILABILITY_AVAILABLE,
            Game::AVAILABILITY_EXPERIMENTAL,
        ]);

        /** @var array<string, Game> $gamesById */
        $gamesById = [];
        foreach ($allGames as $game) {
            $gamesById[$game->getId()] = $game;
        }

        $slots = [];
        foreach ($existingSlots as $slot) {
            $game = $gamesById[$slot['gameId']] ?? null;
            $slots[] = array_merge($slot, [
                'gameName' => $game?->getName() ?? $slot['gameId'],
                'playerYaml' => $slot['playerYaml'] ?? null,
                'apworldHash' => $slot['apworldHash'] ?? null,
            ]);
        }

        $availableGames = array_map(fn (Game $g): array => [
            'id' => $g->getId(),
            'name' => $g->getName(),
            'slug' => $g->getSlug(),
            'description' => $g->getDescription(),
            'availability' => $g->getAvailability(),
            'isApworldReady' => $g->isApworldReady(),
            'defaultYaml' => $g->getDefaultYaml(),
            'coverImageUrl' => $g->getCoverImageUrl(),
            'coverImageAlt' => $g->getCoverImageAlt(),
        ], $allGames);

        return $this->result(found: true, slots: $slots, availableGames: $availableGames);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, slots: list<array<string, mixed>>|null, availableGames: null, errors: array<string, list<string>>}
     */
    public function saveMyGames(string $runId, string $userId, array $input): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return $this->resultWithErrors(found: false);
        }

        $participant = $this->loadParticipant($run, $userId);
        if (null === $participant) {
            return $this->resultWithErrors(found: true, authorized: false);
        }

        if (in_array($run->getStatus(), Run::ACTIVE_STATUSES, true)) {
            return $this->resultWithErrors(found: true, blocked: true, blockReason: 'run_active');
        }

        $gameIds = [];
        if (is_array($input['gameIds'] ?? null)) {
            foreach ($input['gameIds'] as $id) {
                if (is_string($id)) {
                    $gameIds[] = $id;
                }
            }
        }

        $errors = $this->validateGameIds($gameIds);
        if ([] !== $errors) {
            return $this->resultWithErrors(found: true, errors: $errors);
        }

        /** @var array<string, Game> $gamesById */
        $gamesById = [];
        if ([] !== $gameIds) {
            $foundGames = $this->games->findByIds(array_values(array_unique($gameIds)));

            foreach ($foundGames as $game) {
                $gamesById[$game->getId()] = $game;
            }
        }

        $newSlots = $this->diffSlots($participant->getGameSlots(), $gameIds, $gamesById);
        $participant->replaceSlots($newSlots);

        $this->participants->flush();

        $this->logger->info('personal_run.game_selection_saved', ['runId' => $runId, 'userId' => $userId]);

        return $this->resultWithErrors(found: true, slots: $participant->getGameSlots());
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, errors: array<string, list<string>>}
     */
    public function saveSlotYaml(string $runId, string $userId, string $slotId, string $playerYaml): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return $this->yamlResult(found: false);
        }

        $participant = $this->loadParticipant($run, $userId);
        if (null === $participant) {
            return $this->yamlResult(found: true, authorized: false);
        }

        if (in_array($run->getStatus(), Run::ACTIVE_STATUSES, true)) {
            return $this->yamlResult(found: true, blocked: true, blockReason: 'run_active');
        }

        $slot = $participant->getSlot($slotId);
        if (null === $slot) {
            return $this->yamlResult(found: true, errors: ['slotId' => ['Slot introuvable.']]);
        }

        $game = $this->games->findById($slot['gameId']);
        if (!$game instanceof Game) {
            return $this->yamlResult(found: true, errors: ['gameId' => ['Jeu introuvable.']]);
        }

        if (!$game->isApworldReady()) {
            return $this->yamlResult(found: true, errors: ['game' => ["Ce jeu n'a pas encore de fichier .apworld configuré."]]);
        }

        $participant->setSlotPlayerYaml($slotId, $playerYaml, $game->getApworldHash() ?? '');

        $this->participants->flush();

        $this->logger->info('personal_run.slot_yaml_saved', ['runId' => $runId, 'userId' => $userId, 'slotId' => $slotId]);

        return $this->yamlResult(found: true);
    }

    private function loadParticipant(Run $run, string $userId): ?RunParticipant
    {
        if ($run->isOwnedBy($userId)) {
            $participant = $this->participants->findByRunAndUser($run->getId(), $userId);

            if (!$participant instanceof RunParticipant) {
                $participant = RunParticipant::create($run->getId(), $userId, new \DateTimeImmutable());
                $this->participants->save($participant);
            }

            return $participant;
        }

        return $this->participants->findByRunAndUser($run->getId(), $userId);
    }

    /**
     * @param list<string> $gameIds
     *
     * @return array<string, list<string>>
     */
    private function validateGameIds(array $gameIds): array
    {
        $errors = new ValidationErrors();

        foreach ($gameIds as $index => $gameId) {
            $game = $this->games->findById($gameId);
            if (!$game instanceof Game) {
                $errors->add(sprintf('gameIds.%d', $index), 'Jeu introuvable dans la bibliothèque.');
            }
        }

        return $errors->toArray();
    }

    /**
     * @param list<array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null}> $existingSlots
     * @param list<string>                                                                                                     $gameIds
     * @param array<string, Game>                                                                                              $gamesById
     *
     * @return list<array{slotId: string, gameId: string, playerYaml?: string|null, apworldHash?: string|null}>
     */
    private function diffSlots(array $existingSlots, array $gameIds, array $gamesById): array
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
            $game = $gamesById[$gameId] ?? null;
            if (!empty($existingByGameId[$gameId])) {
                $matched = array_shift($existingByGameId[$gameId]);
                $existingYaml = $matched['playerYaml'] ?? null;
                $result[] = [
                    'slotId' => $matched['slotId'],
                    'gameId' => $gameId,
                    'playerYaml' => (null !== $existingYaml && '' !== $existingYaml)
                        ? $existingYaml
                        : $game?->getDefaultYaml(),
                    'apworldHash' => $matched['apworldHash'] ?? $game?->getApworldHash(),
                ];
            } else {
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

    /**
     * @param list<array<string, mixed>>|null $slots
     * @param list<array<string, mixed>>|null $availableGames
     *
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, slots: list<array<string, mixed>>|null, availableGames: list<array<string, mixed>>|null}
     */
    private function result(
        bool $found = false,
        bool $authorized = true,
        bool $blocked = false,
        ?string $blockReason = null,
        ?array $slots = null,
        ?array $availableGames = null,
    ): array {
        return [
            'found' => $found,
            'authorized' => $authorized,
            'blocked' => $blocked,
            'blockReason' => $blockReason,
            'slots' => $slots,
            'availableGames' => $availableGames,
        ];
    }

    /**
     * @param list<array<string, mixed>>|null $slots
     * @param array<string, list<string>>     $errors
     *
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, slots: list<array<string, mixed>>|null, availableGames: null, errors: array<string, list<string>>}
     */
    private function resultWithErrors(
        bool $found = false,
        bool $authorized = true,
        bool $blocked = false,
        ?string $blockReason = null,
        ?array $slots = null,
        array $errors = [],
    ): array {
        return [
            'found' => $found,
            'authorized' => $authorized,
            'blocked' => $blocked,
            'blockReason' => $blockReason,
            'slots' => $slots,
            'availableGames' => null,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, list<string>> $errors
     *
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, errors: array<string, list<string>>}
     */
    private function yamlResult(
        bool $found = false,
        bool $authorized = true,
        bool $blocked = false,
        ?string $blockReason = null,
        array $errors = [],
    ): array {
        return [
            'found' => $found,
            'authorized' => $authorized,
            'blocked' => $blocked,
            'blockReason' => $blockReason,
            'errors' => $errors,
        ];
    }
}
