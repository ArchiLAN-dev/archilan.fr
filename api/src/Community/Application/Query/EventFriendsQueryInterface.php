<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

interface EventFriendsQueryInterface
{
    /**
     * The viewer's accepted friends with a `reserved` registration (submitted or not) to each event, blocks either
     * way excluded. Only events the viewer may see: published, and public or opened to them (private access granted,
     * or registered themselves).
     *
     * @param list<string> $eventIds
     *
     * @return array<string, list<string>> friend user ids keyed by event id, events without a friend left out
     */
    public function friendIdsByEvent(string $viewerId, array $eventIds): array;
}
