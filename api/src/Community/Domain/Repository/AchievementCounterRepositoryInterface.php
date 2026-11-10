<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\AchievementCounter;

/** Story 30.57: the admin's counters over the session feed. */
interface AchievementCounterRepositoryInterface
{
    /**
     * Every counter, by label.
     *
     * @return list<AchievementCounter>
     */
    public function all(): array;

    /**
     * The counters among these ids (unknown ids are skipped).
     *
     * @param list<string> $ids
     *
     * @return list<AchievementCounter>
     */
    public function findByIds(array $ids): array;

    public function findById(string $id): ?AchievementCounter;

    public function save(AchievementCounter $counter): void;

    public function remove(AchievementCounter $counter): void;
}
