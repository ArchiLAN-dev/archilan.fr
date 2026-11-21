<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Repository;

/**
 * Hotfix v1.0.3: the members' counts on a week's objectives, kept by the worker that pays the quests, so a member's
 * progress is read rather than counted during the request.
 */
interface QuestProgressSnapshotRepositoryInterface
{
    /**
     * Replaces the week's counts on these objectives (objective key => member => count) and marks the week counted.
     *
     * @param array<string, array<string, int>> $counts
     */
    public function record(string $weekKey, array $counts, \DateTimeImmutable $countedAt): void;

    /**
     * The member's counts on the week (objective key => count; an objective they have nothing on is absent), or null
     * when the worker has not counted the week yet.
     *
     * @return array<string, int>|null
     */
    public function of(string $weekKey, string $userId): ?array;
}
