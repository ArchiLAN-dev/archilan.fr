<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\ModerationCase;

interface ModerationCaseRepositoryInterface
{
    public function findById(string $id): ?ModerationCase;

    public function findByTargetUserId(string $targetUserId): ?ModerationCase;

    /**
     * Open cases whose member has a DM channel with the bot (story 39.4): the ones whose answers are read.
     *
     * @return list<ModerationCase>
     */
    public function openWithDirectMessageChannel(): array;

    /** Tracks a new case; written by the next flush. */
    public function save(ModerationCase $case): void;

    public function flush(): void;
}
