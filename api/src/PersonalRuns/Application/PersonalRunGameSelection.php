<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application;

use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Application\ValidationErrors;
use App\PersonalRuns\Domain\PersonalRun;
use App\PersonalRuns\Domain\PersonalRunParticipant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class PersonalRunGameSelection
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, slots: list<array<string, mixed>>|null, availableGames: list<array<string, mixed>>|null}
     */
    public function getMySlots(string $runId, string $userId): array
    {
        $run = $this->entityManager->find(PersonalRun::class, $runId);

        if (!$run instanceof PersonalRun) {
            return $this->result(found: false);
        }

        $participant = $this->loadParticipant($runId, $userId);
        if (null === $participant) {
            return $this->result(found: true, authorized: false);
        }

        $existingSlots = $participant->getGameSlots();
        $existingGameIds = array_unique(array_column($existingSlots, 'gameId'));

        /** @var list<ArchipelagoGame> $games */
        $games = $this->entityManager->createQueryBuilder()
            ->select('g')
            ->from(ArchipelagoGame::class, 'g')
            ->where('g.availability IN (:avail)')
            ->setParameter('avail', [
                ArchipelagoGame::AVAILABILITY_AVAILABLE,
                ArchipelagoGame::AVAILABILITY_EXPERIMENTAL,
            ])
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var array<string, ArchipelagoGame> $gamesById */
        $gamesById = [];
        foreach ($games as $game) {
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

        $availableGames = array_map(fn (ArchipelagoGame $g): array => [
            'id' => $g->getId(),
            'name' => $g->getName(),
            'slug' => $g->getSlug(),
            'description' => $g->getDescription(),
            'availability' => $g->getAvailability(),
            'isApworldReady' => $g->isApworldReady(),
            'defaultYaml' => $g->getDefaultYaml(),
            'coverImageUrl' => $g->getCoverImageUrl(),
            'coverImageAlt' => $g->getCoverImageAlt(),
        ], $games);

        return $this->result(found: true, slots: $slots, availableGames: $availableGames);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, slots: list<array<string, mixed>>|null, availableGames: null, errors: array<string, list<string>>}
     */
    public function saveMyGames(string $runId, string $userId, array $input): array
    {
        $run = $this->entityManager->find(PersonalRun::class, $runId);

        if (!$run instanceof PersonalRun) {
            return $this->resultWithErrors(found: false);
        }

        $participant = $this->loadParticipant($runId, $userId);
        if (null === $participant) {
            return $this->resultWithErrors(found: true, authorized: false);
        }

        if (in_array($run->getStatus(), PersonalRun::ACTIVE_STATUSES, true)) {
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

        /** @var array<string, ArchipelagoGame> $gamesById */
        $gamesById = [];
        if ([] !== $gameIds) {
            /** @var list<ArchipelagoGame> $games */
            $games = $this->entityManager->createQueryBuilder()
                ->select('g')
                ->from(ArchipelagoGame::class, 'g')
                ->where('g.id IN (:ids)')
                ->setParameter('ids', array_values(array_unique($gameIds)))
                ->getQuery()
                ->getResult();

            foreach ($games as $game) {
                $gamesById[$game->getId()] = $game;
            }
        }

        $newSlots = $this->diffSlots($participant->getGameSlots(), $gameIds, $gamesById);
        $participant->replaceSlots($newSlots);

        $this->entityManager->flush();

        $this->logger->info('personal_run.game_selection_saved', ['runId' => $runId, 'userId' => $userId]);

        return $this->resultWithErrors(found: true, slots: $participant->getGameSlots());
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, errors: array<string, list<string>>}
     */
    public function saveSlotYaml(string $runId, string $userId, string $slotId, string $playerYaml): array
    {
        $run = $this->entityManager->find(PersonalRun::class, $runId);

        if (!$run instanceof PersonalRun) {
            return $this->yamlResult(found: false);
        }

        $participant = $this->loadParticipant($runId, $userId);
        if (null === $participant) {
            return $this->yamlResult(found: true, authorized: false);
        }

        if (in_array($run->getStatus(), PersonalRun::ACTIVE_STATUSES, true)) {
            return $this->yamlResult(found: true, blocked: true, blockReason: 'run_active');
        }

        $slot = $participant->getSlot($slotId);
        if (null === $slot) {
            return $this->yamlResult(found: true, errors: ['slotId' => ['Slot introuvable.']]);
        }

        $game = $this->entityManager->find(ArchipelagoGame::class, $slot['gameId']);
        if (!$game instanceof ArchipelagoGame) {
            return $this->yamlResult(found: true, errors: ['gameId' => ['Jeu introuvable.']]);
        }

        if (!$game->isApworldReady()) {
            return $this->yamlResult(found: true, errors: ['game' => ["Ce jeu n'a pas encore de fichier .apworld configuré."]]);
        }

        $participant->setSlotPlayerYaml($slotId, $playerYaml, $game->getApworldHash() ?? '');

        $this->entityManager->flush();

        $this->logger->info('personal_run.slot_yaml_saved', ['runId' => $runId, 'userId' => $userId, 'slotId' => $slotId]);

        return $this->yamlResult(found: true);
    }

    private function loadParticipant(string $runId, string $userId): ?PersonalRunParticipant
    {
        $run = $this->entityManager->find(PersonalRun::class, $runId);
        if (!$run instanceof PersonalRun) {
            return null;
        }

        if ($run->isOwnedBy($userId)) {
            $participant = $this->entityManager->find(PersonalRunParticipant::class, [
                'personalRunId' => $runId,
                'userId' => $userId,
            ]);

            if (!$participant instanceof PersonalRunParticipant) {
                $participant = PersonalRunParticipant::create($runId, $userId, new \DateTimeImmutable());
                $this->entityManager->persist($participant);
                $this->entityManager->flush();
            }

            return $participant;
        }

        $participant = $this->entityManager->find(PersonalRunParticipant::class, [
            'personalRunId' => $runId,
            'userId' => $userId,
        ]);

        return $participant instanceof PersonalRunParticipant ? $participant : null;
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
            $game = $this->entityManager->find(ArchipelagoGame::class, $gameId);
            if (!$game instanceof ArchipelagoGame) {
                $errors->add(sprintf('gameIds.%d', $index), 'Jeu introuvable dans la bibliothèque.');
            }
        }

        return $errors->toArray();
    }

    /**
     * @param list<array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null}> $existingSlots
     * @param list<string>                                                                                                     $gameIds
     * @param array<string, ArchipelagoGame>                                                                                   $gamesById
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
