<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Support;

use App\GameSelection\Application\Command\ReconcileApworldIncidentsResult;
use App\GameSelection\Application\Message\NotifyApworldIncidentAdminsJob;
use App\GameSelection\Application\Message\PostApworldIncidentToStaffChannelJob;
use App\GameSelection\Application\Message\StaffAlertEvent;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends the alerts for what a reconciliation changed (story 38.2). Shared by the scheduled handler
 * and the console command, so that a manual run alerts exactly like the scheduled one.
 *
 * One alert per transition: an opened incident goes to the admins in the site and to the staff
 * channel, a closed one only to the staff channel, a recurrence nowhere. Call it only after the
 * reconciliation has committed - it has, when `reconcile()` returns.
 */
final readonly class ApworldIncidentAlertDispatcher
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function dispatchFor(ReconcileApworldIncidentsResult $result): void
    {
        foreach ($result->openedIncidentIds as $incidentId) {
            $this->messageBus->dispatch(new NotifyApworldIncidentAdminsJob($incidentId));
            $this->messageBus->dispatch(new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Opened));
        }
        foreach ($result->resolvedIncidentIds as $incidentId) {
            $this->messageBus->dispatch(new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Resolved));
        }
        foreach ($result->ignoredIncidentIds as $incidentId) {
            $this->messageBus->dispatch(new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Ignored));
        }
    }
}
