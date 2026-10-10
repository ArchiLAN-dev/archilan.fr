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
     * whether the viewer is their friend. A member blocked either way with the viewer is absent. The slot shown
     * comes with its name, whether its goal is reached and whether the run tracks it in detail (story 43.7).
     *
     * @param list<string> $userIds
     *
     * @return array<string, array{sessionId: string, game: string|null, slotName: string|null, goalReached: bool, tracked: bool, visibility: string, friend: bool}>
     */
    public function playing(array $userIds, ?string $viewerId): array;

    /**
     * Of the given users, those whose last session finished since the given time (story 43.5), likewise.
     *
     * @param list<string> $userIds
     *
     * @return array<string, array{sessionId: string, game: string|null, finishedAt: string, visibility: string, friend: bool}>
     */
    public function recentlyFinished(array $userIds, ?string $viewerId, \DateTimeImmutable $since): array;

    /**
     * The slots of the bridge's last players push, per session then per slot name (story 43.7), in one read.
     *
     * @param list<string> $sessionIds
     *
     * @return array<string, array<string, array<array-key, mixed>>>
     */
    public function snapshotSlots(array $sessionIds): array;

    /**
     * Every listable member currently in a live session, most recently active first, likewise.
     *
     * @return list<array{userId: string, sessionId: string, game: string|null, visibility: string, friend: bool}>
     */
    public function playingNow(?string $viewerId): array;
}
