<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * "Currently playing" as a viewer may see it (stories 30.14 and 43.6): a member whose presence visibility leaves
 * this viewer out, or blocked either way with them, reads as not playing. The member always sees themselves.
 */
interface CommunityPresenceQueryInterface
{
    /**
     * Of the given users, those currently in a live (running) session, keyed by userId. A user not in the
     * map is not playing, or not for this viewer.
     *
     * @param list<string> $userIds
     *
     * @return array<string, array{sessionId: string, game: string|null}>
     */
    public function playing(array $userIds, ?string $viewerId): array;

    /**
     * Everyone currently in a live (running) session, most recently started first, capped at $limit.
     * Unlike {@see playing()} this takes no id list - it answers "who is playing right now" for the
     * community hub (story 30.38). Restricted to listable members (a public slug, not deleted) so the
     * rows can always be rendered as a profile link.
     *
     * @return list<array{userId: string, sessionId: string, game: string|null}>
     */
    public function playingNow(int $limit, ?string $viewerId): array;
}
