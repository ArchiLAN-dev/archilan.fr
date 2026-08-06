<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

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

    /**
     * Everyone currently in a live (running) session, most recently started first, capped at $limit.
     * Unlike {@see playing()} this takes no id list - it answers "who is playing right now" for the
     * community hub (story 30.38). Restricted to listable members (a public slug, not deleted) so the
     * rows can always be rendered as a profile link.
     *
     * @return list<array{userId: string, sessionId: string, game: string|null}>
     */
    public function playingNow(int $limit): array;
}
