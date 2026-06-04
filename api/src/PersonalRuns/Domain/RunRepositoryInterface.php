<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain;

interface RunRepositoryInterface
{
    public function findById(string $id): ?Run;

    /**
     * @return list<Run>
     */
    public function findByOwnerId(string $ownerId): array;

    public function findByInviteToken(string $inviteToken): ?Run;

    public function findBySessionId(string $sessionId): ?Run;

    public function save(Run $run): void;

    public function delete(Run $run): void;

    public function flush(): void;
}
