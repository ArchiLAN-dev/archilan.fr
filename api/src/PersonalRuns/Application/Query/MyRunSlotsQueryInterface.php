<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Query;

/**
 * The slots a member plays in a run's session - the ones they own and the ones they co-play (story
 * 16.17) - so the connection block can tell them which name to type in their client (story 17.29).
 */
interface MyRunSlotsQueryInterface
{
    /**
     * In slot order. The game is null for a slot of an imported seed (no catalogue game).
     *
     * @return list<array{name: string, game: string|null, gameId: string|null, gameSlug: string|null}>
     */
    public function forMember(string $sessionId, string $userId): array;
}
