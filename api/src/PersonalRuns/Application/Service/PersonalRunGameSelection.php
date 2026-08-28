<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Service;

use App\Community\Application\Query\CommunityLevelQuery;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Identity\Application\Support\ValidationErrors;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Application\Message\RunSlotPreflightJob;
use App\PersonalRuns\Application\Port\RunGameAssignmentInterface;
use App\PersonalRuns\Application\Query\RecentlyPlayedGamesQueryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Shared\Domain\ValueObject\SlotName;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class PersonalRunGameSelection implements RunGameAssignmentInterface
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private GameRepositoryInterface $games,
        private RecentlyPlayedGamesQueryInterface $recentlyPlayedGames,
        private UserRepositoryInterface $users,
        private CommunityUserDirectoryQueryInterface $directory,
        private CommunityLevelQuery $levels,
        private RunnerGatewayInterface $runnerGateway,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private RunSlotCoPlayers $coPlayers,
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

        $coPlayersBySlot = $this->coPlayers->forSlots(array_map(
            static fn (array $slot): string => $slot['slotId'],
            $existingSlots,
        ));

        $slots = [];
        foreach ($existingSlots as $slot) {
            $game = $gamesById[$slot['gameId']] ?? null;
            $slots[] = array_merge($slot, [
                'gameName' => $game?->getName() ?? $slot['gameId'],
                'playerYaml' => $slot['playerYaml'] ?? null,
                'apworldHash' => $slot['apworldHash'] ?? null,
                // Story 16.17: the owner sees who else is on the slot, without being able to
                // change it - that is the run owner's call.
                'coPlayers' => $coPlayersBySlot[$slot['slotId']] ?? [],
            ]);
        }

        $availableGames = array_map(fn (Game $g): array => [
            'id' => $g->getId(),
            'name' => $g->getName(),
            'slug' => $g->getSlug(),
            'description' => $g->getDescription(),
            'availability' => $g->getAvailability(),
            'disabled' => $g->isDisabled(),
            'disabledMessage' => $g->getDisabledMessage(),
            'isApworldReady' => $g->isApworldReady(),
            'defaultYaml' => $g->getDefaultYaml(),
            'optionTypes' => $g->getEffectiveOptionTypes(),
            'locationNames' => $g->getLocationNames(),
            'coverImageUrl' => $g->getCoverImageUrl(),
            'coverImageAlt' => $g->getCoverImageAlt(),
            'platforms' => $g->platformFamilies(),
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

        $coPlayersBySlot = $this->coPlayers->forSlots(array_map(
            static fn (array $slot): string => $slot['slotId'],
            $existingSlots,
        ));

        $slots = [];
        foreach ($existingSlots as $slot) {
            $game = $gamesById[$slot['gameId']] ?? null;
            $playerYaml = $slot['playerYaml'] ?? null;
            $slots[] = [
                'slotId' => $slot['slotId'],
                // Story 16.17: who else plays this slot, shown to every participant - playing
                // together is not private information.
                'coPlayers' => $coPlayersBySlot[$slot['slotId']] ?? [],
                'gameId' => $slot['gameId'],
                'slotOrder' => $slot['slotOrder'],
                'gameName' => null !== $game ? $game->getName() : $slot['gameId'],
                'gameSlug' => $game?->getSlug(),
                'description' => $game?->getDescription(),
                'coverImageUrl' => $game?->getCoverImageUrl(),
                'coverImageAlt' => null !== $game ? $game->getCoverImageAlt() : $slot['gameId'],
                'availability' => $game?->getAvailability(),
                'platforms' => null !== $game ? $game->platformFamilies() : [],
                'isApworldReady' => null !== $game && $game->isApworldReady(),
                'playerYaml' => (null !== $playerYaml && '' !== $playerYaml) ? $playerYaml : null,
                // Story 9.42 review fix: the owner's per-participant view shows the solo
                // test-generation verdict too, not just the aggregated launch warning.
                'preflight' => $slot['preflight'] ?? null,
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

        $disabledErrors = $this->validateDisabledGames($gameIds, $participant->getGameSlots(), $gamesById);
        if ([] !== $disabledErrors) {
            return $this->resultWithErrors(found: true, errors: $disabledErrors);
        }

        $preflightErrors = $this->validateApworldPreflights($gameIds, $participant->getGameSlots(), $gamesById);
        if ([] !== $preflightErrors) {
            return $this->resultWithErrors(found: true, errors: $preflightErrors);
        }

        $slotIdsBefore = array_map(static fn (array $slot): string => $slot['slotId'], $participant->getGameSlots());

        $newSlots = $this->diffSlots($participant->getGameSlots(), $gameIds, $gamesById);
        $participant->replaceSlots($newSlots);

        $this->participants->flush();

        // Dropping a game drops the people who were playing it with you (story 16.17): a roster
        // attached to a slot that no longer exists would be invisible and un-removable.
        $slotIdsAfter = array_map(static fn (array $slot): string => $slot['slotId'], $participant->getGameSlots());
        $this->coPlayers->forget(array_values(array_diff($slotIdsBefore, $slotIdsAfter)));

        $this->logger->info('personal_run.game_selection_saved', ['runId' => $runId, 'userId' => $userId]);

        return $this->resultWithErrors(found: true, slots: $participant->getGameSlots());
    }

    /**
     * Why this game cannot be added to a brand-new selection - empty when it can (story 17.23).
     *
     * Single source of truth for "is this game addable", shared with {@see saveMyGames}: the run
     * creation path validates through here *before* creating anything, so a game that cannot be
     * added never leaves an empty run behind. Existing slots are deliberately passed as empty -
     * the leniency that lets an already-selected game survive a later disable does not apply to a
     * selection that does not exist yet.
     *
     * @return list<string>
     */
    public function reasonsGameCannotBeAdded(string $gameId): array
    {
        $gameIds = [$gameId];

        $missing = $this->validateGameIds($gameIds);
        if ([] !== $missing) {
            return $missing['gameIds.0'] ?? ['Jeu introuvable dans la bibliothèque.'];
        }

        /** @var array<string, Game> $gamesById */
        $gamesById = [];
        foreach ($this->games->findByIds($gameIds) as $game) {
            $gamesById[$game->getId()] = $game;
        }

        $disabled = $this->validateDisabledGames($gameIds, [], $gamesById);
        if ([] !== $disabled) {
            return $disabled['gameIds.0'] ?? [];
        }

        $preflight = $this->validateApworldPreflights($gameIds, [], $gamesById);

        return $preflight['gameIds.0'] ?? [];
    }

    public function assignGameToCreator(string $runId, string $ownerId, string $gameId): void
    {
        $this->saveMyGames($runId, $ownerId, ['gameIds' => [$gameId]]);
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

        $participant->submitSlotPlayerYaml($slotId, $playerYaml, $game->getApworldHash() ?? '');

        // Story 9.42: every saved yaml gets an automatic solo test generation. The verdict
        // is advisory (never blocks a launch) and keyed to this exact yaml.
        $yamlSha = hash('sha256', $playerYaml);
        $participant->recordSlotPreflight($slotId, 'pending', '', $yamlSha, $this->clock->now());

        $this->participants->flush();

        $this->messageBus->dispatch(new RunSlotPreflightJob($runId, $userId, $slotId, $yamlSha));

        $this->logger->info('personal_run.slot_yaml_saved', ['runId' => $runId, 'userId' => $userId, 'slotId' => $slotId]);

        return $this->yamlResult(found: true);
    }

    /**
     * Story 9.42: explicit "Tester ma config" re-run of the solo test generation for one slot.
     *
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, errors: array<string, list<string>>}
     */
    public function requestSlotPreflight(string $runId, string $userId, string $slotId): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return $this->yamlResult(found: false);
        }

        $participant = $this->loadParticipant($run, $userId);
        if (null === $participant) {
            return $this->yamlResult(found: true, authorized: false);
        }

        $slot = $participant->getSlot($slotId);
        if (null === $slot) {
            return $this->yamlResult(found: true, errors: ['slotId' => ['Slot introuvable.']]);
        }

        $yaml = is_string($slot['playerYaml'] ?? null) ? $slot['playerYaml'] : '';
        if ('' === $yaml) {
            return $this->yamlResult(found: true, errors: ['playerYaml' => ['Configure d\'abord un YAML pour ce slot.']]);
        }

        $yamlSha = hash('sha256', $yaml);
        $participant->recordSlotPreflight($slotId, 'pending', '', $yamlSha, $this->clock->now());
        $this->participants->flush();

        $this->messageBus->dispatch(new RunSlotPreflightJob($runId, $userId, $slotId, $yamlSha));

        $this->logger->info('personal_run.slot_preflight_requested', ['runId' => $runId, 'userId' => $userId, 'slotId' => $slotId]);

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
                $participant = RunParticipant::create($run->getId(), $userId, $this->clock->now());
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
     * A game disabled by an admin (story 11.4) cannot be NEWLY added, but slots that already
     * reference it keep resolving: re-submitting an unchanged selection stays accepted, so a
     * later disable never bricks an existing run.
     *
     * @param list<string>                                                                                                     $gameIds
     * @param list<array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null}> $existingSlots
     * @param array<string, Game>                                                                                              $gamesById
     *
     * @return array<string, list<string>>
     */
    private function validateDisabledGames(array $gameIds, array $existingSlots, array $gamesById): array
    {
        $errors = new ValidationErrors();

        $existingCounts = array_count_values(array_column($existingSlots, 'gameId'));

        foreach ($gameIds as $index => $gameId) {
            $game = $gamesById[$gameId] ?? null;
            if (null === $game || !$game->isDisabled()) {
                continue;
            }

            if (($existingCounts[$gameId] ?? 0) > 0) {
                --$existingCounts[$gameId];
                continue;
            }

            $message = $game->getDisabledMessage();
            $errors->add(
                sprintf('gameIds.%d', $index),
                null !== $message
                    ? sprintf('Ce jeu est temporairement désactivé : %s', $message)
                    : 'Ce jeu est temporairement désactivé.',
            );
        }

        return $errors->toArray();
    }

    /**
     * Story 9.38 AC4: a newly added game whose apworld failed its upload-time preflight test
     * generation (and has no admin override) cannot be attached. One runner fetch per save;
     * when the runner is unreachable the verdict map is empty and nothing is blocked (fail
     * open). Games already present in the participant's slots are never re-blocked.
     *
     * @param list<string>                                                                                                     $gameIds
     * @param list<array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null}> $existingSlots
     * @param array<string, Game>                                                                                              $gamesById
     *
     * @return array<string, list<string>>
     */
    private function validateApworldPreflights(array $gameIds, array $existingSlots, array $gamesById): array
    {
        $hashesByGameId = [];
        foreach ($gameIds as $gameId) {
            $hash = ($gamesById[$gameId] ?? null)?->getApworldHash();
            if (null !== $hash && '' !== $hash) {
                $hashesByGameId[$gameId] = $hash;
            }
        }
        if ([] === $hashesByGameId) {
            return [];
        }

        $verdicts = $this->runnerGateway->fetchApworldPreflights();
        if ([] === $verdicts) {
            return [];
        }

        $errors = new ValidationErrors();
        $existingCounts = array_count_values(array_column($existingSlots, 'gameId'));

        foreach ($gameIds as $index => $gameId) {
            $hash = $hashesByGameId[$gameId] ?? null;
            $verdict = null !== $hash ? ($verdicts[$hash] ?? null) : null;
            if (null === $verdict || !$verdict['blocks']) {
                continue;
            }

            if (($existingCounts[$gameId] ?? 0) > 0) {
                --$existingCounts[$gameId];
                continue;
            }

            $errors->add(
                sprintf('gameIds.%d', $index),
                'Le monde Archipelago de ce jeu a échoué au test de génération ; il ne peut pas être ajouté pour le moment.',
            );
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
            if ([] !== ($existingByGameId[$gameId] ?? [])) {
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
