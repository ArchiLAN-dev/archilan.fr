<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * What a viewer may know of the sessions their friends play in (story 43.5): the kind always, the title and the
 * page only when the viewer has access - a public event, or a personal run they own or take part in.
 */
interface FriendSessionContextQueryInterface
{
    /**
     * @param list<string> $sessionIds
     *
     * @return array<string, array{kind: 'event'|'run', title: string|null, eventId: string|null, runId: string|null}> keyed by session id
     */
    public function forViewer(array $sessionIds, string $viewerId): array;
}
