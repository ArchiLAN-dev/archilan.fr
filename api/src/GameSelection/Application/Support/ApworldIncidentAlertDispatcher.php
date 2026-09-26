<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Support;

use App\GameSelection\Application\Command\DecideApworldCandidatesResult;
use App\GameSelection\Application\Command\ReconcileApworldIncidentsResult;
use App\GameSelection\Application\Message\ApworldPromoted;
use App\GameSelection\Application\Message\NotifyApworldIncidentAdminsJob;
use App\GameSelection\Application\Message\PostApworldIncidentToStaffChannelJob;
use App\GameSelection\Application\Message\PostApworldPromotionToStaffChannelJob;
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
        $this->dispatchTransitions($result->openedIncidentIds, $result->resolvedIncidentIds, $result->ignoredIncidentIds);
    }

    /**
     * Story 38.6: a promotion is announced to the staff; a rejection opened an "update rejected"
     * incident, which alerts like any other; the update incidents a promotion settled are closed.
     * Story 38.7: a promotion is also published for the contexts holding slots of the game.
     */
    public function dispatchForDecisions(DecideApworldCandidatesResult $result): void
    {
        $staffPosts = 0;
        foreach ($result->promotions as $promotion) {
            $this->postToStaff(new PostApworldPromotionToStaffChannelJob($promotion->candidateId, $promotion->previousVersion), $staffPosts++);
            $this->messageBus->dispatch(ApworldPromoted::of($promotion));
        }
        $this->dispatchTransitions($result->openedIncidentIds, $result->resolvedIncidentIds, [], $staffPosts);
    }

    /**
     * @param list<string> $opened
     * @param list<string> $resolved
     * @param list<string> $ignored
     */
    private function dispatchTransitions(array $opened, array $resolved, array $ignored, int $staffPosts = 0): void
    {
        foreach ($opened as $incidentId) {
            $this->messageBus->dispatch(new NotifyApworldIncidentAdminsJob($incidentId));
            $this->postToStaff(new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Opened), $staffPosts++);
        }
        foreach ($resolved as $incidentId) {
            $this->postToStaff(new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Resolved), $staffPosts++);
        }
        foreach ($ignored as $incidentId) {
            $this->postToStaff(new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Ignored), $staffPosts++);
        }
    }

    private function postToStaff(object $job, int $rank): void
    {
        $this->messageBus->dispatch($job, 0 === $rank ? [] : [new DelayStamp($rank * self::STAFF_POST_SPACING_MS)]);
    }
}
