<?php

declare(strict_types=1);

namespace App\Community\Domain;

interface ModerationActionRepositoryInterface
{
    public function save(ModerationAction $action): void;

    /**
     * Action history for one account, most recent first.
     *
     * @return list<ModerationAction>
     */
    public function forTarget(string $targetUserId, int $limit): array;

    // Connection-level transaction control (shared with the other repos on the same EM), so a
    // suspend/ban + audit + report auto-resolution commits atomically (story 30.29).
    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;
}
