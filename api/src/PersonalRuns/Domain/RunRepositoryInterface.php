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

    /**
     * Runs the user participates in but does **not** own (joined via invite link).
     *
     * @return list<Run>
     */
    public function findJoinedByUserId(string $userId): array;

    public function findByInviteToken(string $inviteToken): ?Run;

    public function findBySessionId(string $sessionId): ?Run;

    /**
     * @param list<string> $statuses
     *
     * @return list<Run>
     */
    public function findByStatuses(array $statuses): array;

    public function save(Run $run): void;

    public function delete(Run $run): void;

    public function flush(): void;
}
