<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Community\Domain\Entity\AchievementCounter;

/** Story 30.57: what the admin counters give one member, read in the session feed. */
interface FeedCounterQueryInterface
{
    /**
     * One query per counter. A counter whose game has no Archipelago name (or no longer exists) is worth 0.
     *
     * @param list<AchievementCounter> $counters
     *
     * @return array<string, int> counter id => value
     */
    public function values(string $userId, array $counters): array;
}
