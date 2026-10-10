<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * Who a member played with (story 43.16), on the co-play aggregate of story 43.2: the players of a session slot,
 * owners and co-players, in a personal run or an event session. A weekly attempt is a solo race and never counts.
 */
interface SocialPlayQueryInterface
{
    /**
     * `coplayers`: the distinct members played with. `friends`: those of them who are accepted friends today.
     * `maxFinishedWithSamePerson`: the most finished sessions shared with one member. `weeklyDuelsWon`: the weekly
     * duels won (story 43.15).
     *
     * @return array{coplayers: int, friends: int, maxFinishedWithSamePerson: int, weeklyDuelsWon: int}
     */
    public function forUser(string $userId): array;
}
