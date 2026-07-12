<?php

declare(strict_types=1);

namespace App\Sessions\Domain;

interface SessionRecapRepositoryInterface
{
    public function findBySessionId(string $sessionId): ?SessionRecap;

    public function save(SessionRecap $recap): void;
}
