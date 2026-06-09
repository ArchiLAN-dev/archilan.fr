<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain;

interface SessionConfigOverrideRepositoryInterface
{
    public function find(string $sessionId): ?SessionConfigOverride;

    public function save(string $sessionId, SessionConfigOverride $override): void;
}
