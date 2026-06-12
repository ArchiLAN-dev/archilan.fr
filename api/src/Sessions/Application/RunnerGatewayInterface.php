<?php

declare(strict_types=1);

namespace App\Sessions\Application;

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
