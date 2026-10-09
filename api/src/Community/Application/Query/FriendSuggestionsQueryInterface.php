<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * The members a member has played with and could add as friends (story 43.2).
 */
interface FriendSuggestionsQueryInterface
{
    /** A personal run is a chosen group: one played together is enough. */
    public const int MIN_SHARED_RUNS = 1;

    /** An event session gathers everyone registered: one big LAN played once is not enough. */
    public const int MIN_SHARED_EVENT_SESSIONS = 2;

    /**
     * Members who shared a session with the user - owner or co-player of a slot, in a personal run or an event
     * session - at least MIN_SHARED_RUNS runs or MIN_SHARED_EVENT_SESSIONS event sessions, most sessions
     * together first, then the latest. Left out: the user, anyone with a friendship row with them (accepted,
     * pending or declined, either way), a block either way, and the members the user dismissed. With a
     * session, only that session's players, and nothing if the user did not play it.
     *
     * @return list<array{userId: string, sessionsTogether: int, lastTitle: string|null, lastPlayedAt: string|null}>
     */
    public function forUser(string $userId, ?string $sessionId, int $limit): array;
}
