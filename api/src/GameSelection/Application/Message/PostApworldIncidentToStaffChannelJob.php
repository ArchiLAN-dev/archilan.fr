<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Message;

/**
 * Announce an apworld incident transition on the staff channel (story 38.2). Separate from the
 * in-app notification job so that a retry of one never replays the other.
 */
final readonly class PostApworldIncidentToStaffChannelJob
{
    public function __construct(
        public string $incidentId,
        public StaffAlertEvent $event,
    ) {
    }
}
