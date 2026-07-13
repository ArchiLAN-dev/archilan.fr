<?php

declare(strict_types=1);

namespace App\Sessions\Application\Port;

/**
 * Posts a runner-side status callback back to the central API.
 *
 * The runner jobs (archive, fetch-logs) run on the runner host and report their outcome
 * over HTTP. That HTTP call is Infrastructure; this port is what the Application handlers
 * depend on (AC-A2/AC-A5), which is what lets the two
 * `ALLOWED_APPLICATION_INFRASTRUCTURE_IMPORTS` allowlist entries be deleted (story 33.20).
 */
interface RunnerCallbackClientInterface
{
    /** @param array<string, mixed> $payload */
    public function sendCallback(string $sessionId, array $payload): void;
}
