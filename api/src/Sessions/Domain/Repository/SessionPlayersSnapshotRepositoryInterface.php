<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Repository;

use App\Sessions\Domain\Entity\SessionPlayersSnapshot;

interface SessionPlayersSnapshotRepositoryInterface
{
    public function findBySessionId(string $sessionId): ?SessionPlayersSnapshot;

    public function save(SessionPlayersSnapshot $snapshot): void;
}
