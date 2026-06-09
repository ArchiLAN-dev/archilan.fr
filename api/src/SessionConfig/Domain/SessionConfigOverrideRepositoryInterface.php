<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain;

/**
 * Per-scope config override. The scope key is a stable identifier the admin/owner controls:
 * the template id for weekly runs, the session id for event sessions, the run id for private runs.
 */
interface SessionConfigOverrideRepositoryInterface
{
    public function find(string $scopeKey): ?SessionConfigOverride;

    public function save(string $scopeKey, SessionConfigOverride $override): void;

    public function delete(string $scopeKey): void;
}
