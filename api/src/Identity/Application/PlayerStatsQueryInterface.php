<?php

declare(strict_types=1);

namespace App\Identity\Application;

interface PlayerStatsQueryInterface
{
    /**
     * @return array{runs_participated: int, games_played: int, goal_completions: int, total_checks_done: int, total_items_received: int}
     */
    public function computeForUser(string $userId): array;

    /**
     * Batch variant of {@see computeForUser}, computed identically. Pass null for every user with finished
     * activity; users with none are simply absent from the map (no zero rows).
     *
     * @param list<string>|null $userIds
     *
     * @return array<string, array{runs_participated: int, games_played: int, goal_completions: int, total_checks_done: int, total_items_received: int}>
     */
    public function computeForUsers(?array $userIds): array;
}
