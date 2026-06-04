<?php

declare(strict_types=1);

namespace App\Identity\Application;

interface PlayerStatsQueryInterface
{
    /**
     * @return array{runs_participated: int, goal_completions: int, total_checks_done: int, total_items_received: int}
     */
    public function computeForUser(string $userId): array;
}
