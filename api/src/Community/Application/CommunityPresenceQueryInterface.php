<?php

declare(strict_types=1);

namespace App\Community\Application;

interface CommunityPresenceQueryInterface
{
    /**
     * Of the given users, those currently in a live (running) session, keyed by userId. A user not in the
     * map is not playing. Reuses the existing session / session_slot data (story 30.14).
     *
     * @param list<string> $userIds
     *
     * @return array<string, array{sessionId: string, game: string|null}>
     */
    public function playing(array $userIds): array;
}
