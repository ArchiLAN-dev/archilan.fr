<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

/**
 * A session's persisted feed, oldest first, read one entry at a time.
 *
 * A long async run holds tens of thousands of events: hydrating them all as entities, then encoding them in one
 * piece, went past the PHP memory limit. The entries come as plain rows from a cursor and are encoded as they go.
 *
 * @phpstan-type FeedEntry array{
 *     id: string,
 *     type: string,
 *     text: string,
 *     occurredAt: string,
 *     item: array{id: ?int, name: ?string, flags: ?int},
 *     location: array{id: ?int, name: ?string},
 *     sender: array{slot: ?int, name: ?string, game: ?string},
 *     receiver: array{slot: ?int, name: ?string, game: ?string},
 *     victims: list<string>,
 * }
 */
interface SessionFeedEntriesQueryInterface
{
    /**
     * @return iterable<int, FeedEntry>
     */
    public function forSession(string $sessionId): iterable;
}
