<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

interface SharedHistoryQueryInterface
{
    /**
     * The sessions two members played together (story 43.9), personal runs and event sessions, most recent first.
     *
     * @return list<array{sessionId: string, runId: string|null, eventId: string|null, title: string|null, playedAt: string}>
     */
    public function sessionsBetween(string $userId, string $otherId): array;

    /**
     * The items one sent the other across those sessions, from their persisted feed (epic 32): `sent` from the first
     * member to the second, `received` the other way, `since` the earliest feed event among them (null: no feed).
     *
     * @param list<string> $sessionIds
     *
     * @return array{sent: int, received: int, since: string|null}
     */
    public function itemsBetween(string $userId, string $otherId, array $sessionIds): array;
}
