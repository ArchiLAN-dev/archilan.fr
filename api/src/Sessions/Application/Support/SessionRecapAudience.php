<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

use App\Events\Domain\Repository\EventRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Domain\Entity\Session;

/**
 * The single access rule for a session's recap surfaces - the exchange graph (story 32.5) and the feed
 * timeline (story 32.6). Kept in one place because both expose past game activity and must never leak a
 * private run: a drift between two copies of this rule is exactly the bug we avoid.
 *
 * A session's `eventId` is overloaded - a real Event, else a personal Run id. Event recaps are visible
 * to anyone for a **public** event. Personal-run recaps are visible to the run **owner and
 * participants**, and to anyone once the owner **publishes** them. This is never the spoiler's
 * owner/admin-only gate: it only ever shows what already happened.
 */
final readonly class SessionRecapAudience
{
    public function __construct(
        private EventRepositoryInterface $events,
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
    ) {
    }

    public function canView(Session $session, ?string $viewerId): bool
    {
        $event = $this->events->findById($session->getEventId());
        if (null !== $event) {
            return $event->isPublic();
        }

        $run = $this->runs->findById($session->getEventId());
        if (null === $run) {
            return false;
        }
        if ($run->isRecapPublic()) {
            return true;
        }
        if (null === $viewerId) {
            return false;
        }
        if ($run->isOwnedBy($viewerId)) {
            return true;
        }

        return null !== $this->participants->findByRunAndUser($run->getId(), $viewerId);
    }

    /** The VOD URL for an event session; null for a personal run (no VOD concept). */
    public function vodUrl(Session $session): ?string
    {
        return $this->events->findById($session->getEventId())?->getVodUrl();
    }
}
