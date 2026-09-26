<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Support;

use App\GameSelection\Application\Command\ReconcileApworldIncidentsResult;
use App\GameSelection\Application\Message\NotifyApworldIncidentAdminsJob;
use App\GameSelection\Application\Message\PostApworldIncidentToStaffChannelJob;
use App\GameSelection\Application\Message\StaffAlertEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

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
    /**
     * Delay between two staff posts of one pass (story 38.2 review): a Discord webhook takes about five
     * posts per two seconds, and the first pass after a deploy may open dozens of incidents at once.
     */
    public const int STAFF_POST_SPACING_MS = 500;

    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function dispatchFor(ReconcileApworldIncidentsResult $result): void
    {
        $staffPosts = 0;
        foreach ($result->openedIncidentIds as $incidentId) {
            $this->messageBus->dispatch(new NotifyApworldIncidentAdminsJob($incidentId));
            $this->postToStaff(new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Opened), $staffPosts++);
        }
        foreach ($result->resolvedIncidentIds as $incidentId) {
            $this->postToStaff(new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Resolved), $staffPosts++);
        }
        foreach ($result->ignoredIncidentIds as $incidentId) {
            $this->postToStaff(new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Ignored), $staffPosts++);
        }
    }

    private function postToStaff(PostApworldIncidentToStaffChannelJob $job, int $rank): void
    {
        $this->messageBus->dispatch($job, 0 === $rank ? [] : [new DelayStamp($rank * self::STAFF_POST_SPACING_MS)]);
    }
}
