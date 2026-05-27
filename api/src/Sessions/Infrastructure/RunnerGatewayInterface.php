<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

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
     * @param list<array{slotName: string, apworldHash: string, playerYaml: string}> $slots
     *
     * @return array{valid: bool, errors: list<array{playerName: string, errors: list<string>}>}
     */
    public function configureSession(string $sessionId, array $slots): array;

    public function generateSession(string $sessionId, string $adminPassword, ?string $seed = null): void;

    public function launchSession(string $sessionId, string $adminPassword, string $serverPassword): void;

    public function stopSession(string $sessionId): void;

    public function restartSession(string $sessionId): void;

    /**
     * Returns the orchestrateur's view of the session, or null if the session is unknown.
     *
     * @return array{status: string, bridgePort: ?int}|null
     */
    public function getSessionInfo(string $sessionId): ?array;
}
