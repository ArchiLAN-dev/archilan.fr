<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Sessions\Application\Support\SessionRecapAudience;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRecapRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;

/**
 * Which sessions in a list a given viewer may open a recap for (story 32.20).
 *
 * Answers the only question a listing needs before deciding whether to render a link: a row that
 * cannot be opened must not be a link at all, rather than a link to an error page.
 *
 * Two conditions, both required:
 *  - the recap is **viewable** - delegated to {@see SessionRecapAudience}, never re-implemented
 *    here, because a second copy of that rule is exactly how a private run leaks;
 *  - the recap **exists** - a session finished before story 32.1 passes the first test and fails
 *    this one, and treating it as viewable would produce the dead link this whole story removes.
 *
 * Evaluated per list, never per row: a 20-entry page of a player profile costs two grouped reads.
 */
final readonly class ViewableRecapsQuery
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private SessionRecapRepositoryInterface $recaps,
        private SessionRecapAudience $audience,
    ) {
    }

    /**
     * @param list<string> $sessionIds
     *
     * @return array<string, bool> session id => may this viewer open its recap
     */
    public function forViewer(array $sessionIds, ?string $viewerId): array
    {
        $wanted = array_values(array_unique(array_filter($sessionIds, static fn (string $id): bool => '' !== $id)));
        if ([] === $wanted) {
            return [];
        }

        $viewable = array_fill_keys($wanted, false);

        $finished = [];
        foreach ($this->sessions->findByIds($wanted) as $session) {
            if (Session::STATUS_FINISHED === $session->getStatus() && $this->audience->canView($session, $viewerId)) {
                $finished[] = $session->getId();
            }
        }

        foreach ($this->recaps->findExistingSessionIds($finished) as $sessionId) {
            $viewable[$sessionId] = true;
        }

        return $viewable;
    }
}
