<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Support;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;

/**
 * Story 56.5: the target duration a player's world is measured against - the event's (the admin's, or its length
 * minus 20 %), or the private run's for the people in it. Null without one.
 */
final readonly class YamlTestTarget
{
    public function __construct(
        private EventRepositoryInterface $events,
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
    ) {
    }

    public function hours(string $userId, ?string $eventId, ?string $runId): ?float
    {
        if (null !== $eventId) {
            $event = $this->events->findById($eventId);

            return $event instanceof Event ? $event->targetHours() : null;
        }
        if (null !== $runId) {
            $run = $this->runs->findById($runId);
            if (!$run instanceof Run) {
                return null;
            }
            // A run's target is read by the people in it only.
            $isIn = $run->isOwnedBy($userId) || $this->participants->findByRunAndUser($runId, $userId) instanceof RunParticipant;
            $hours = $run->getTargetHours();

            return $isIn && null !== $hours ? (float) $hours : null;
        }

        return null;
    }
}
