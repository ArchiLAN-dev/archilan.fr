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
}
