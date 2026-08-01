<?php

declare(strict_types=1);

namespace App\Sessions\Application\Port;

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
     * @return array<string, mixed>
     */
    public function uploadApworld(string $fileContents, string $filename): array;

    /**
     * Authoritative range bounds per option key for an uploaded apworld (story 9.25).
     *
     * @return array<string, array{min: int, max: int, default: int|null}>
     */
    public function fetchOptionTypes(string $hash): array;

    /**
     * Static location names introspected from an uploaded apworld (the World class's
     * location_name_to_id keys); a free-text suggestion hint for location-typed YAML options
     * (story 4.14). Empty when the apworld has not been introspected yet.
     *
     * @return list<string>
     */
    public function fetchLocationNames(string $hash): array;

    /**
     * Upload-time preflight verdicts for all uploaded apworlds, keyed by hash (story 9.38).
     * `blocks` is true only for a failed, non-overridden verdict. Apworlds never checked
     * (uploaded before the preflight existed) appear with an empty status and blocks=false.
     * Returns an empty array when the runner is unreachable - callers MUST fail open (never
     * block on missing data).
     *
     * @return array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}>
     */
    public function fetchApworldPreflights(): array;

    /**
     * Re-run the upload-time preflight for one apworld (asynchronous on the orchestrator).
     * Returns false when the runner rejected the request or is unreachable.
     */
    public function runApworldPreflight(string $hash): bool;

    /**
     * Toggle the admin "force allow" override on a preflight verdict (story 9.38 AC4).
     * Returns the updated verdict, or null when the runner is unreachable.
     *
     * @return array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}|null
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
     */
    public function startSlotPreflight(string $playerYaml, ?string $apworldHash): ?string;

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
     */
    public function generateSession(string $sessionId, string $adminPassword, ?string $seed = null, array $generationOptions = []): void;

    /**
     * @param array<string, scalar> $serverOptions
     */
    public function launchSession(string $sessionId, string $adminPassword, string $serverPassword, array $serverOptions = []): void;

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
