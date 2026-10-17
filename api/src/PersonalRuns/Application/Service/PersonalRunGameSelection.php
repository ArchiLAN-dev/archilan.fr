<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Service;

use App\Community\Application\Query\CommunityLevelQuery;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\GameSelection\Application\Support\GenerationFiles;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Identity\Application\Support\ValidationErrors;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Application\Handler\NotifyRunGamesChosenJobHandler;
use App\PersonalRuns\Application\Message\NotifyRunGamesChosenJob;
use App\PersonalRuns\Application\Message\RunSlotPreflightJob;
use App\PersonalRuns\Application\Port\RunGameAssignmentInterface;
use App\PersonalRuns\Application\Query\RecentlyPlayedGamesQueryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Exception\RequiredGenerationFileRefusedException;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Sessions\Application\Service\RequiredGenerationFiles;
use App\Sessions\Application\Support\ProvidedGenerationFile;
use App\Shared\Domain\ValueObject\SlotName;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class PersonalRunGameSelection implements RunGameAssignmentInterface
{
    /** Twice the slot test's own polling deadline (RunSlotPreflightJobHandler, ~7 minutes). */
    private const int STALE_PENDING_SECONDS = 15 * 60;

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
        private RequiredGenerationFiles $requiredGenerationFiles,
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
        $now = $this->clock->now();
        foreach ($existingSlots as $slot) {
            $game = $gamesById[$slot['gameId']] ?? null;
            $slots[] = array_merge($slot, [
                'preflight' => self::withoutStalePending($slot['preflight'] ?? null, $now),
                'gameName' => $game?->getName() ?? $slot['gameId'],
                'playerYaml' => $slot['playerYaml'] ?? null,
                'apworldHash' => $slot['apworldHash'] ?? null,
                // Story 16.17: the owner sees who else is on the slot, without being able to
                // change it - that is the run owner's call.
                'coPlayers' => $coPlayersBySlot[$slot['slotId']] ?? [],
                // Story 44.3: the files the game may read from the launcher (a ROM...).
                'requiredFiles' => null !== $game ? GenerationFiles::ofGame($game) : [],
            ]);
        }

        // The default YAML, option types and location names only for the games the member holds: the YAML
        // editor of a slot needs them, the picker does not. Sent for the whole catalog, they made a response
        // of tens of megabytes that ran PHP out of memory.
        $held = array_column($existingSlots, 'gameId');
        // Story 38.16: the catalog says which games a save would refuse for a failed generation test (story 9.38
        // AC4), instead of letting the member pick one and meet « Sélection invalide ». Fails open, like the save.
        $verdicts = $this->runnerGateway->fetchApworldPreflights();
        $availableGames = array_map(fn (Game $g): array => [
            'id' => $g->getId(),
            'name' => $g->getName(),
            'slug' => $g->getSlug(),
            'description' => $g->getDescription(),
            'availability' => $g->getAvailability(),
            'disabled' => $g->isDisabled(),
            'disabledMessage' => $g->getDisabledMessage(),
            'preflightBlocked' => true === ($verdicts[$g->getApworldHash() ?? '']['blocks'] ?? false),
            'isApworldReady' => $g->isApworldReady(),
            'requiredFiles' => GenerationFiles::ofGame($g),
            ...self::editorData($g, \in_array($g->getId(), $held, true)),
            'coverImageUrl' => $g->getCoverImageUrl(),
            'coverImageAlt' => $g->getCoverImageAlt(),
            'platforms' => $g->platformFamilies(),
            'steamAppId' => $g->getSteamAppId(),
        ], $this->offerable($allGames, $existingSlots));

        $recentlyPlayed = $this->recentlyPlayedGames->recentlyPlayed($userId, $runId, 3);

        return $this->result(found: true, status: $run->getStatus(), slots: $slots, availableGames: $availableGames, recentlyPlayedGames: $recentlyPlayed);
    }

    /**
     * The games a member picked in each of their runs, in slot order, keyed by run id (story 36.9): the admin
     * sheet shows them on a draft or a paused run, which has no finished party to tell them. Two queries
     * whatever the number of runs - the member's participations, then their games.
     *
     * @return array<string, list<string>>
     */
    public function gamesByRunForMember(string $userId): array
    {
        $participations = $this->participants->findByUserId($userId);

        $gameIds = [];
        foreach ($participations as $participation) {
            foreach ($participation->getGameSlots() as $slot) {
                $gameIds[] = $slot['gameId'];
            }
        }
        $names = [];
        foreach ([] === $gameIds ? [] : $this->games->findByIds(array_values(array_unique($gameIds))) as $game) {
            $names[$game->getId()] = $game->getName();
        }

        $byRun = [];
        foreach ($participations as $participation) {
            $slots = $participation->getGameSlots();
            usort($slots, static fn (array $a, array $b): int => $a['slotOrder'] <=> $b['slotOrder']);
            $games = [];
            foreach ($slots as $slot) {
                $name = $names[$slot['gameId']] ?? $slot['gameId'];
                if (!in_array($name, $games, true)) {
                    $games[] = $name;
                }
            }
            $byRun[$participation->getRunId()] = $games;
        }

        return $byRun;
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
                // Story 38.7: the owner sees a slot to review like its player does.
                'needsReview' => $slot['needsReview'] ?? [],
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
            'avatarAnimatedUrl' => null !== $card ? $card['avatarAnimatedUrl'] : null,
            'avatarFrame' => null !== $card ? $card['avatarFrame'] : null,
            'avatarFraming' => null !== $card ? $card['avatarFraming'] : null,
            'nameStyle' => null !== $card ? $card['nameStyle'] : null,
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
        $gameIdsBefore = NotifyRunGamesChosenJobHandler::gameIdsOf($participant);

        $newSlots = $this->diffSlots($participant->getGameSlots(), $gameIds, $gamesById);
        $participant->replaceSlots($newSlots);

        $this->participants->flush();

        // Dropping a game drops the people who were playing it with you (story 16.17): a roster
        // attached to a slot that no longer exists would be invisible and un-removable.
        $slotIdsAfter = array_map(static fn (array $slot): string => $slot['slotId'], $participant->getGameSlots());
        $this->coPlayers->forget($runId, array_values(array_diff($slotIdsBefore, $slotIdsAfter)));

        $this->logger->info('personal_run.game_selection_saved', ['runId' => $runId, 'userId' => $userId]);

        // Story 43.28: the owner hears which games a player settled on, once they stop changing them.
        $gameIdsAfter = NotifyRunGamesChosenJobHandler::gameIdsOf($participant);
        if (!$run->isOwnedBy($userId) && [] !== $gameIdsAfter && $gameIdsAfter !== $gameIdsBefore) {
            $this->messageBus->dispatch(
                new NotifyRunGamesChosenJob($runId, $userId, $gameIdsAfter),
                [new DelayStamp(NotifyRunGamesChosenJob::SETTLE_MS)],
            );
        }

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

        // Story 44.7: a game that reads a file at generation (a ROM) is only tested with it - the automatic test
        // could only fail on its absence. Saving forgot the previous verdict; the player tests with the ROM.
        if ($game->needsAFileAtGeneration()) {
            $this->participants->flush();
            $this->logger->info('personal_run.slot_yaml_saved', ['runId' => $runId, 'userId' => $userId, 'slotId' => $slotId]);

            return $this->yamlResult(found: true);
        }

        // Story 9.42: every saved yaml gets an automatic solo test generation. The verdict
        // is advisory (never blocks a launch) and keyed to this exact yaml.
        $yamlSha = hash('sha256', $playerYaml);
        $participant->recordSlotPreflight($slotId, 'pending', '', $yamlSha, $this->clock->now());

        $this->participants->flush();

        $this->messageBus->dispatch(new RunSlotPreflightJob($runId, $userId, $slotId, $yamlSha, apworldHash: $participant->getSlot($slotId)['apworldHash'] ?? null));

        $this->logger->info('personal_run.slot_yaml_saved', ['runId' => $runId, 'userId' => $userId, 'slotId' => $slotId]);

        return $this->yamlResult(found: true);
    }

    /**
     * Story 9.42: explicit "Tester ma config" re-run of the solo test generation for one slot, with the files the
     * player joined (story 44.7): handed to the orchestrator here, before anything changes, so a refused file
     * leaves the slot as it was. Only their ids travel in the job.
     *
     * @param list<ProvidedGenerationFile> $files
     *
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, errors: array<string, list<string>>}
     */
    public function requestSlotPreflight(string $runId, string $userId, string $slotId, array $files = []): array
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

        try {
            $fileIds = $this->requiredGenerationFiles->stage($files);
        } catch (RequiredGenerationFileRefusedException) {
            return $this->yamlResult(found: true, errors: ['requiredFiles' => ['Le fichier n\'a pas pu être transmis. Réessaie dans un instant.']]);
        }

        $yamlSha = hash('sha256', $yaml);
        try {
            $participant->recordSlotPreflight($slotId, 'pending', '', $yamlSha, $this->clock->now());
            $this->participants->flush();

            $this->messageBus->dispatch(new RunSlotPreflightJob($runId, $userId, $slotId, $yamlSha, apworldHash: $slot['apworldHash'] ?? null, requiredFileIds: $fileIds));
        } catch (\Throwable $e) {
            // Nothing will consume the held files: give them back rather than let them wait for their expiry.
            $this->requiredGenerationFiles->release($fileIds);

            throw $e;
        }

        $this->logger->info('personal_run.slot_preflight_requested', ['runId' => $runId, 'userId' => $userId, 'slotId' => $slotId]);

        return $this->yamlResult(found: true);
    }

    /**
     * Review of epic 44: a test still pending long after the handler's own deadline (~7 minutes of polling) lost its
     * job - a worker that could not read it, a restart. Shown as a failure the player can run again, instead of a
     * « Test en cours… » that hides the button for good.
     *
     * @param array{status: string, error: string, checkedAt: string, yamlSha: string}|null $preflight
     *
     * @return array{status: string, error: string, checkedAt: string, yamlSha: string}|null
     */
    private static function withoutStalePending(?array $preflight, \DateTimeImmutable $now): ?array
    {
        if (null === $preflight || 'pending' !== $preflight['status']) {
            return $preflight;
        }
        $since = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $preflight['checkedAt']);
        if (false === $since || $now->getTimestamp() - $since->getTimestamp() < self::STALE_PENDING_SECONDS) {
            return $preflight;
        }

        return [...$preflight, 'status' => 'failed', 'error' => 'Le test n\'a pas répondu : relance-le.'];
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

    /**
     * What the YAML editor of a slot reads - null for a game the member does not hold.
     *
     * @return array{defaultYaml: string|null, optionTypes: array<string, array<string, mixed>>|null, locationNames: list<string>|null}
     */
    private static function editorData(Game $game, bool $held): array
    {
        return [
            'defaultYaml' => $held ? $game->getDefaultYaml() : null,
            'optionTypes' => $held ? $game->getEffectiveOptionTypes() : null,
            'locationNames' => $held ? $game->getLocationNames() : null,
        ];
    }

    /**
     * Story 11.5: a disabled game is no longer offered - except to a participant who already holds it, so that
     * slot keeps its config (flagged as disabled, it still cannot be added again).
     *
     * @param list<Game>                  $games
     * @param list<array{gameId: string}> $slots
     *
     * @return list<Game>
     */
    private function offerable(array $games, array $slots): array
    {
        $held = array_column($slots, 'gameId');

        return array_values(array_filter(
            $games,
            static fn (Game $game): bool => !$game->isDisabled() || \in_array($game->getId(), $held, true),
        ));
    }
}
