<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Query\ActiveRegistrationQueryInterface;
use App\Sessions\Domain\Entity\Session;

/**
 * The single access rule for a session's recap surfaces - the exchange graph (story 32.5) and the feed
 * timeline (story 32.6). Kept in one place because both expose past game activity and must never leak a
 * private run: a drift between two copies of this rule is exactly the bug we avoid.
 *
 * A session's `eventId` is overloaded - a real Event, else a personal Run id. Event recaps are visible
 * to anyone for a **public** event, and for a **private** one to its confirmed registrants and the admins (story 4.23). Personal-run recaps are visible to the run **owner and
 * participants**, and to anyone once the owner **publishes** them. This is never the spoiler's
 * owner/admin-only gate: it only ever shows what already happened.
 */
final readonly class SessionRecapAudience
{
    public function __construct(
        private EventRepositoryInterface $events,
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private ActiveRegistrationQueryInterface $registrations,
        private UserRepositoryInterface $users,
    ) {
    }

    public function canView(Session $session, ?string $viewerId): bool
    {
        $event = $this->events->findById($session->getEventId());
        if (null !== $event) {
            return $this->canViewEvent($event, $viewerId);
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

    /** Story 4.23: an event's game list and recaps follow the same rule as each of its sessions. */
    public function canViewEvent(Event $event, ?string $viewerId): bool
    {
        if ($event->isPublic()) {
            return true;
        }
        if (null === $viewerId) {
            return false;
        }
        if ($this->registrations->hasActiveForEvent($viewerId, $event->getId())) {
            return true;
        }

        $viewer = $this->users->findById($viewerId);

        return $viewer instanceof User && in_array('ROLE_ADMIN', $viewer->getRoles(), true);
    }

    /** The VOD URL for an event session; null for a personal run (no VOD concept). */
    public function vodUrl(Session $session): ?string
    {
        return $this->events->findById($session->getEventId())?->getVodUrl();
    }
}
