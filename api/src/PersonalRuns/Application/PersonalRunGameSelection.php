<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application;

use App\Community\Application\CommunityLevelQuery;
use App\Community\Application\CommunityUserDirectoryQueryInterface;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\GameSelection\Domain\PlatformCategory;
use App\Identity\Application\ValidationErrors;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipant;
use App\PersonalRuns\Domain\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Shared\Domain\SlotName;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class PersonalRunGameSelection
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private GameRepositoryInterface $games,
        private RecentlyPlayedGamesQueryInterface $recentlyPlayedGames,
        private UserRepositoryInterface $users,
        private CommunityUserDirectoryQueryInterface $directory,
        private CommunityLevelQuery $levels,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, status: string|null, slots: list<array<string, mixed>>|null, availableGames: list<array<string, mixed>>|null, recentlyPlayedGames: list<array{gameId: string, lastPlayedAt: string, runTitle: string}>}
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
            'optionTypes' => $g->getOptionTypes(),
            'coverImageUrl' => $g->getCoverImageUrl(),
            'coverImageAlt' => $g->getCoverImageAlt(),
            'platforms' => PlatformCategory::families($g->getPlatforms() ?? []),
            'steamAppId' => $g->getSteamAppId(),
        ], $allGames);

        $recentlyPlayed = $this->recentlyPlayedGames->recentlyPlayed($userId, $runId, 3);

        return $this->result(found: true, status: $run->getStatus(), slots: $slots, availableGames: $availableGames, recentlyPlayedGames: $recentlyPlayed);
    }

    /**
     * Read-only projection of another participant's identity + slots + applied YAML. Authorized for the
     * run owner or any participant of the run (collaborative visibility); never editable.
     *
     * @return array{found: bool, authorized: bool, participant: array<string, mixed>|null, slots: list<array<string, mixed>>|null}
     */
    public function getParticipantSlots(string $runId, string $viewerId, string $participantId): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return $this->participantResult(found: false);
        }

        $isOwner = $run->isOwnedBy($viewerId);
        $isViewerParticipant = $this->participants->findByRunAndUser($run->getId(), $viewerId) instanceof RunParticipant;
        if (!$isOwner && !$isViewerParticipant) {
            return $this->participantResult(found: true, authorized: false);
        }

        $participant = $this->participants->findByRunAndUser($run->getId(), $participantId);
        if (!$participant instanceof RunParticipant) {
            return $this->participantResult(found: false);
        }

        $identity = $this->resolveParticipant($participantId);

        $existingSlots = $participant->getGameSlots();

        $gameIds = array_values(array_unique(array_map(
            static fn (array $slot): string => $slot['gameId'],
            $existingSlots,
        )));

        /** @var array<string, Game> $gamesById */
        $gamesById = [];
        if ([] !== $gameIds) {
            foreach ($this->games->findByIds($gameIds) as $game) {
                $gamesById[$game->getId()] = $game;
            }
        }

        $slots = [];
        foreach ($existingSlots as $slot) {
            $game = $gamesById[$slot['gameId']] ?? null;
            $playerYaml = $slot['playerYaml'] ?? null;
            $slots[] = [
                'slotId' => $slot['slotId'],
                'gameId' => $slot['gameId'],
                'slotOrder' => $slot['slotOrder'],
                'gameName' => null !== $game ? $game->getName() : $slot['gameId'],
                'gameSlug' => $game?->getSlug(),
                'description' => $game?->getDescription(),
                'coverImageUrl' => $game?->getCoverImageUrl(),
                'coverImageAlt' => null !== $game ? $game->getCoverImageAlt() : $slot['gameId'],
                'availability' => $game?->getAvailability(),
                'platforms' => null !== $game ? PlatformCategory::families($game->getPlatforms() ?? []) : [],
                'isApworldReady' => null !== $game && $game->isApworldReady(),
                'playerYaml' => (null !== $playerYaml && '' !== $playerYaml) ? $playerYaml : null,
            ];
        }

        return $this->participantResult(found: true, participant: $identity, slots: $slots);
    }

    /**
     * Resolve a participant's public identity (community pseudo + avatar + slug) plus their community
     * level/XP and headline stats, so the participant detail page can present them like the public
     * profile. Identity falls back to the account display name when no visible community card; XP is
     * computed from the canonical components, exactly as the public profile does.
     *
     * @return array<string, mixed>
     */
    private function resolveParticipant(string $userId): array
    {
        $card = $this->directory->cards([$userId])[$userId] ?? null;
        $user = $this->users->findByIds([$userId])[0] ?? null;

        $level = $this->levels->levelFor($userId);

        return [
            'userId' => $userId,
            'slug' => null !== $card ? $card['slug'] : null,
            'displayName' => (null !== $card ? $card['displayName'] : null)
                ?? ($user instanceof User ? $user->getDisplayName() : null),
            'avatarUrl' => null !== $card ? $card['avatarUrl'] : null,
            'isAdmin' => $user instanceof User && in_array('ROLE_ADMIN', $user->getRoles(), true),
            'level' => [
                'level' => $level['level'],
                'xp' => $level['xp'],
                'xpIntoLevel' => $level['xpIntoLevel'],
                'xpForNextLevel' => $level['xpForNextLevel'],
            ],
            'stats' => [
                'runsParticipated' => $level['runsParticipated'],
                'goalCompletions' => $level['goalCompletions'],
                'totalChecksDone' => $level['totalChecksDone'],
                'achievementsUnlocked' => $level['achievementsUnlocked'],
            ],
        ];
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

        if ($run->isLockedForEditing()) {
            return $this->resultWithErrors(found: true, blocked: true, blockReason: 'run_generated');
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

        if ($run->isLockedForEditing()) {
            return $this->yamlResult(found: true, blocked: true, blockReason: 'run_generated');
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

        $nameError = $this->slotNameError($playerYaml);
        if (null !== $nameError) {
            return $this->yamlResult(found: true, errors: ['name' => [$nameError]]);
        }

        $participant->setSlotPlayerYaml($slotId, $playerYaml, $game->getApworldHash() ?? '');

        $this->participants->flush();

        $this->logger->info('personal_run.slot_yaml_saved', ['runId' => $runId, 'userId' => $userId, 'slotId' => $slotId]);

        return $this->yamlResult(found: true);
    }

    /**
     * Validates the YAML `name:` (slot name) charset/length. Returns an error message, or null when
     * the name is valid or absent/unparseable (a broken YAML fails later in the pipeline).
     */
    private function slotNameError(string $playerYaml): ?string
    {
        try {
            $parsed = Yaml::parse($playerYaml);
        } catch (ParseException) {
            return null;
        }

        if (!is_array($parsed) || !is_string($parsed['name'] ?? null)) {
            return null;
        }

        if (!SlotName::isValid($parsed['name'])) {
            return sprintf(
                'Nom de slot invalide : seuls les lettres, chiffres, _ et les placeholders {number}/{player} sont autorisés (%d caractères max).',
                SlotName::MAX_LENGTH,
            );
        }

        return null;
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
     * @param list<array<string, mixed>>|null                                     $slots
     * @param list<array<string, mixed>>|null                                     $availableGames
     * @param list<array{gameId: string, lastPlayedAt: string, runTitle: string}> $recentlyPlayedGames
     *
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, status: string|null, slots: list<array<string, mixed>>|null, availableGames: list<array<string, mixed>>|null, recentlyPlayedGames: list<array{gameId: string, lastPlayedAt: string, runTitle: string}>}
     */
    private function result(
        bool $found = false,
        bool $authorized = true,
        bool $blocked = false,
        ?string $blockReason = null,
        ?string $status = null,
        ?array $slots = null,
        ?array $availableGames = null,
        array $recentlyPlayedGames = [],
    ): array {
        return [
            'found' => $found,
            'authorized' => $authorized,
            'blocked' => $blocked,
            'blockReason' => $blockReason,
            'status' => $status,
            'slots' => $slots,
            'availableGames' => $availableGames,
            'recentlyPlayedGames' => $recentlyPlayedGames,
        ];
    }

    /**
     * @param array<string, mixed>|null       $participant
     * @param list<array<string, mixed>>|null $slots
     *
     * @return array{found: bool, authorized: bool, participant: array<string, mixed>|null, slots: list<array<string, mixed>>|null}
     */
    private function participantResult(bool $found = false, bool $authorized = true, ?array $participant = null, ?array $slots = null): array
    {
        return [
            'found' => $found,
            'authorized' => $authorized,
            'participant' => $participant,
            'slots' => $slots,
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
