<?php

declare(strict_types=1);

namespace App\Sessions\Application\Port;

use App\Sessions\Application\Exception\RequiredGenerationFileExpiredException;
use App\Sessions\Application\Exception\RequiredGenerationFileRefusedException;

interface RunnerGatewayInterface
{
    /**
     * @param list<array<string, mixed>> $slots
     *
     * @return array<string, mixed>
     */
    public function preflight(string $sessionId, array $slots): array;

    /**
     * On success: returns array with keys storageKey, hash, archipelagoGameName, defaultYaml (all strings).
     * On failure: returns array with key error (string).
     *
     * $withPartner gives the test generation the upload starts a partner player (story 38.30).
     *
     * @return array<string, mixed>
     */
    public function uploadApworld(string $fileContents, string $filename, bool $withPartner = false): array;

    /**
     * What the apworld says each of its options is, per option key.
     *
     * Started life as range bounds only (story 9.25); widened to the whole type table by story 9.33,
     * so the editor can stop guessing an option's nature from the shape of its value. `min` / `max`
     * stay where they were for the range consumers.
     *
     * On a `dict`, `values` holds the sub-setting NAMES and `keys` the values each of them accepts
     * (story 9.51). The two are not interchangeable: reading one as the other offers key names in a
     * dropdown as though they were values.
     *
     * @return array<string, array{type: string, min?: int, max?: int, default?: int|string|bool|null, values?: list<string>, keys?: array<string, array{values: list<string>}>}>
     */
    public function fetchOptionTypes(string $hash): array;

    /**
     * Re-runs option introspection on an apworld already in the runner's storage (story 9.53).
     *
     * Introspection otherwise happens exactly once, when the apworld is uploaded, so a world
     * introspected by an older image keeps that answer for good. Before this, the only way to
     * refresh it was to re-upload the very bytes the runner already holds.
     *
     * Returns false when the runner refused: the stored introspection is then left untouched, and
     * the caller keeps serving the previous - stale but complete - answer.
     */
    public function reintrospectApworld(string $hash): bool;

    /**
     * Static location names introspected from an uploaded apworld (the World class's
     * location_name_to_id keys); a free-text suggestion hint for location-typed YAML options
     * (story 4.14). Empty for a world that declares none (story 38.31); null when the orchestrator does not
     * answer, or holds no readable introspection for that apworld.
     *
     * @return list<string>|null
     */
    public function fetchLocationNames(string $hash): ?array;

    /**
     * Static item names and item groups introspected from an uploaded apworld (story 9.58): a hint for the
     * item-typed YAML options, like the locations. Empty lists when the apworld has not been introspected by an
     * image that knows items, or when the orchestrator does not answer (an older one has no such route).
     *
     * @return array{items: list<string>, groups: array<string, list<string>>}
     */
    public function fetchItems(string $hash): array;

    /**
     * The files an uploaded apworld's world declares in its host.yaml settings (story 44.3), read at
     * introspection. Empty when not introspected yet or when the orchestrator does not answer.
     *
     * @return list<array{setting: string, file: string, extension: string, description: ?string, md5s: list<string>}>
     */
    public function fetchDeclaredFiles(string $hash): array;

    /**
     * By Archipelago game name, the files the worlds shipped with the image declare (story 44.3).
     * Null when the orchestrator does not answer or predates them.
     *
     * @return array<string, list<array{setting: string, file: string, extension: string, description: ?string, md5s: list<string>}>>|null
     */
    public function fetchOfficialDeclaredFiles(): ?array;

    /**
     * Upload-time preflight verdicts for all uploaded apworlds, keyed by hash (story 9.38).
     * `blocks` is true only for a failed, non-overridden verdict. Apworlds never checked
     * (uploaded before the preflight existed) appear with an empty status and blocks=false.
     * Returns an empty array when the runner is unreachable - callers MUST fail open (never
     * block on missing data).
     *
     * @return array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool, image?: string|null, imageId?: string|null, warning?: string, withRequiredFiles?: bool, withPartner?: bool}>
     */
    public function fetchApworldPreflights(): array;

    /**
     * The Archipelago image the orchestrator runs (story 38.8): its reference and the id of the local
     * image, null when not inspected. Null when the orchestrator does not answer.
     *
     * @return array{apImage: string, apImageId: string|null}|null
     */
    public function fetchRuntime(): ?array;

    /**
     * Re-run the upload-time preflight for one apworld (asynchronous on the orchestrator).
     * Returns false when the runner rejected the request or is unreachable.
     *
     * @param list<string> $requiredFileIds held files the test reads, provided by an admin (story 44.6); the
     *                                      verdict then says withRequiredFiles
     * @param bool         $withPartner     adds a partner player, for a meta-game that cannot generate solo
     *                                      (story 38.30); the verdict then says withPartner
     */
    public function runApworldPreflight(string $hash, array $requiredFileIds = [], bool $withPartner = false): bool;

    /**
     * Toggle the admin "force allow" override on a preflight verdict (story 9.38 AC4).
     * Returns the updated verdict, or null when the runner is unreachable.
     *
     * @return array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool, image?: string|null, imageId?: string|null, warning?: string, withRequiredFiles?: bool, withPartner?: bool}|null
     */
    public function overrideApworldPreflight(string $hash, bool $overridden): ?array;

    /**
     * Replace the YAML template stored next to an apworld (story 9.45), so the upload
     * preflight tests what the platform actually serves to players. Returns false when the
     * runner is unreachable - the caller keeps the player-facing value it just saved.
     */
    public function setApworldTemplate(string $hash, string $template): bool;

    /**
     * Regenerate the template from the stored apworld (story 9.46). Returns the fresh
     * template, or null when the world still cannot produce one - the stored template is
     * then left untouched, so a failure never blanks a working value.
     *
     * @return array{template: string}|array{error: string}
     */
    public function regenerateApworldTemplate(string $hash): array;

    /**
     * Queue a solo test generation of one player's real YAML (story 9.42). $apworldHash is
     * null for official worlds bundled in the generation image. Returns the orchestrator job
     * id to poll with getSlotPreflight, or null when the runner is unreachable.
     *
     * @param list<string> $requiredFileIds held files the test reads (story 44.6)
     */
    public function startSlotPreflight(string $playerYaml, ?string $apworldHash, array $requiredFileIds = []): ?string;

    /**
     * Poll a slot preflight job. Returns null for unknown/expired ids or runner errors (the
     * caller decides whether to retry or give up).
     *
     * @return array{status: string, error: string}|null status: pending|passed|failed
     */
    public function getSlotPreflight(string $jobId): ?array;

    /**
     * @param list<array{slotName: string, apworldHash: string, playerYaml: string}> $slots
     *
     * @return array{valid: bool, errors: list<array{playerName: string, errors: list<string>}>}
     */
    public function configureSession(string $sessionId, array $slots): array;

    /**
     * @param array<string, mixed> $generationOptions
     * @param list<string>         $requiredFileIds   held files this generation consumes (story 44.2)
     *
     * @throws RequiredGenerationFileExpiredException when one of them is no longer held
     */
    public function generateSession(string $sessionId, string $adminPassword, ?string $seed = null, array $generationOptions = [], array $requiredFileIds = []): void;

    /**
     * Hands a file a world reads at generation to the orchestrator, which holds it in memory for one
     * generation only (story 44.2). Streamed from $path.
     *
     * @return string the one-use id the generation names it by
     *
     * @throws RequiredGenerationFileRefusedException
     */
    public function stageRequiredFile(string $setting, string $path, string $filename): string;

    /** Drops a held file before its generation; never throws, the file expires anyway. */
    public function releaseRequiredFile(string $stagedFileId): void;

    /**
     * @param array<string, scalar> $serverOptions
     */
    public function launchSession(string $sessionId, string $adminPassword, ?string $serverPassword, array $serverOptions = []): void;

    public function stopSession(string $sessionId): void;

    public function restartSession(string $sessionId): void;

    /**
     * Resume an idle session: the orchestrateur relaunches the AP server on the retained session
     * volume so the latest Archipelago save is reloaded (epic-17 restart redesign).
     */
    public function relaunchFromSave(string $sessionId): void;

    /**
     * Returns the orchestrateur's view of the session, or null if the session is unknown.
     *
     * @return array{status: string, bridgePort: ?int, apPort: ?int}|null
     */
    public function getSessionInfo(string $sessionId): ?array;
}
