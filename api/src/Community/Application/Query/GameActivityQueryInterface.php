<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * Story 30.55: the connected time and the DeathLink deaths of the slots a member plays (owner or co-player), by
 * game, over all their sessions.
 */
interface GameActivityQueryInterface
{
    /**
     * @return array<string, array{playedSeconds: int, deaths: int}> game id => activity
     */
    public function forUser(string $userId): array;
}
