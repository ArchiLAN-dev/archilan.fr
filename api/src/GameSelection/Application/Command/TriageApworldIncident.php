<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Message\PostApworldIncidentToStaffChannelJob;
use App\GameSelection\Application\Message\StaffAlertEvent;
use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Exception\ApworldIncidentTransitionException;
use App\GameSelection\Domain\Repository\ApworldIncidentRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * An admin acts on an apworld incident from the health page (story 38.3): takes it, resolves it or
 * ignores it. Each action is one unit of work - the domain transition, one commit - followed by the
 * staff channel alert of story 38.2, so the channel reads "Jean s'occupe de Crystal Project" as the
 * follow-up the team asked for.
 *
 * The lifecycle rules stay in {@see ApworldIncident}: a forbidden transition is reported, never
 * forced, and nothing is committed or sent for it.
 */
final readonly class TriageApworldIncident
{
    public function __construct(
        private ApworldIncidentRepositoryInterface $incidents,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    public function acknowledge(string $incidentId, string $adminId): ApworldIncidentTriageOutcome
    {
        return $this->apply(
            $incidentId,
            StaffAlertEvent::Acknowledged,
            fn (ApworldIncident $incident) => $incident->acknowledge($adminId, $this->clock->now()),
        );
    }

    public function resolve(string $incidentId, string $adminId): ApworldIncidentTriageOutcome
    {
        return $this->apply(
            $incidentId,
            StaffAlertEvent::Resolved,
            fn (ApworldIncident $incident) => $incident->resolve($this->clock->now(), $adminId),
        );
    }

    public function ignore(string $incidentId, string $adminId): ApworldIncidentTriageOutcome
    {
        return $this->apply(
            $incidentId,
            StaffAlertEvent::Ignored,
            fn (ApworldIncident $incident) => $incident->ignore($this->clock->now(), $adminId),
        );
    }

    /**
     * @param callable(ApworldIncident): void $transition
     */
    private function apply(string $incidentId, StaffAlertEvent $event, callable $transition): ApworldIncidentTriageOutcome
    {
        $incident = $this->incidents->findById($incidentId);
        if (null === $incident) {
            return ApworldIncidentTriageOutcome::NotFound;
        }

        try {
            $transition($incident);
        } catch (ApworldIncidentTransitionException) {
            return ApworldIncidentTriageOutcome::Forbidden;
        }

        $this->incidents->flush();
        $this->messageBus->dispatch(new PostApworldIncidentToStaffChannelJob($incidentId, $event));

        return ApworldIncidentTriageOutcome::Applied;
    }
}
