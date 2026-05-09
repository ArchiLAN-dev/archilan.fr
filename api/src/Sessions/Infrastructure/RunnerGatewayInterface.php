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
     * On success: returns array with keys storageKey, hash, archipelagoGameName, defaultYaml (all non-empty strings).
     * On failure: returns array with key error (string).
     *
     * @return array<string, mixed>
     */
    public function uploadApworld(string $fileContents, string $filename): array;

    /**
     * @param list<array<string, mixed>> $slots
     *
     * @return array<string, mixed>
     */
    public function writeYamls(string $sessionId, array $slots): array;

    /** @return array<string, mixed> */
    public function generate(string $sessionId): array;

    /** @return array<string, mixed> */
    public function launch(string $sessionId): array;

    /** @return array<string, mixed> */
    public function restart(string $sessionId): array;

    /** @return array<string, mixed> */
    public function stop(string $sessionId): array;

    public function getYamlsZip(string $sessionId): ?string;
}
