<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\ModerationCase;

interface ModerationCaseRepositoryInterface
{
    public function findById(string $id): ?ModerationCase;

    public function findByTargetUserId(string $targetUserId): ?ModerationCase;

    /**
     * Cases whose member has a DM channel with the bot and whose answers are read (story 39.4): the open ones,
     * and those closed since the given moment (story 39.9: the staff may still answer after a lift).
     *
     * @return list<ModerationCase>
     */
    public function withDirectMessagesToRead(\DateTimeImmutable $closedSince): array;

    /** Tracks a new case; written by the next flush. */
    public function save(ModerationCase $case): void;

    public function flush(): void;
}
