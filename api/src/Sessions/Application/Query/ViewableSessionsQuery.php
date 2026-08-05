<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Sessions\Application\Support\SessionRecapAudience;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;

/**
 * Which sessions in a list a given viewer may know the content of, whatever their status (story 30.38).
 *
 * The sibling {@see ViewableRecapsQuery} answers a narrower question - may this viewer *open the recap* -
 * and therefore requires a finished session with a stored recap. A live surface ("who is playing right
 * now") needs the audience rule alone, applied to a `running` session that has no recap yet.
 *
 * The rule itself is never re-implemented here: it is delegated to {@see SessionRecapAudience}, for the
 * reason that class documents - a second copy of it is exactly how a private run leaks. Evaluated per
 * list, never per row.
 */
final readonly class ViewableSessionsQuery
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private SessionRecapAudience $audience,
    ) {
    }

    /**
     * @param list<string> $sessionIds
     *
     * @return array<string, bool> session id => may this viewer see what is played in it
     */
    public function forViewer(array $sessionIds, ?string $viewerId): array
    {
        $wanted = array_values(array_unique(array_filter($sessionIds, static fn (string $id): bool => '' !== $id)));
        if ([] === $wanted) {
            return [];
        }

        $viewable = array_fill_keys($wanted, false);

        foreach ($this->sessions->findByIds($wanted) as $session) {
            $viewable[$session->getId()] = $this->audience->canView($session, $viewerId);
        }

        return $viewable;
    }
}
