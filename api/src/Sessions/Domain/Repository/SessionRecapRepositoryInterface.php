<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Repository;

use App\Sessions\Domain\Entity\SessionRecap;

interface SessionRecapRepositoryInterface
{
    public function findBySessionId(string $sessionId): ?SessionRecap;

    /**
     * Which of these sessions actually have a recap - one read, not one per id (story 32.20).
     *
     * @param list<string> $sessionIds
     *
     * @return list<string> the subset that has one
     */
    public function findExistingSessionIds(array $sessionIds): array;

    public function save(SessionRecap $recap): void;
}
