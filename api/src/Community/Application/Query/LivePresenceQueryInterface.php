<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * The raw "currently playing" read (story 30.14), before the members' presence visibility is applied (story 43.6).
 * Only {@see CommunityPresenceQuery} reads it: every other caller goes through CommunityPresenceQueryInterface, so
 * no surface can show a presence its member chose to hide.
 */
interface LivePresenceQueryInterface
{
    /**
     * Of the given users, those currently in a live session, keyed by userId, with their presence visibility and
     * whether the viewer is their friend. A member blocked either way with the viewer is absent.
     *
     * @param list<string> $userIds
     *
     * @return array<string, array{sessionId: string, game: string|null, visibility: string, friend: bool}>
     */
    public function playing(array $userIds, ?string $viewerId): array;

    /**
     * Every listable member currently in a live session, most recently active first, likewise.
     *
     * @return list<array{userId: string, sessionId: string, game: string|null, visibility: string, friend: bool}>
     */
    public function playingNow(?string $viewerId): array;
}
