<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Repository;

use App\Sessions\Domain\Entity\SessionRecap;

interface SessionRecapRepositoryInterface
{
    public function findBySessionId(string $sessionId): ?SessionRecap;

    public function save(SessionRecap $recap): void;
}
