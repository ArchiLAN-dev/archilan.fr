<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Message;

/**
 * Tell every admin, in the site, that an apworld incident was just opened (story 38.2). Dispatched
 * after the reconciliation has committed; carries the id only, the handler reads the current state.
 */
final readonly class NotifyApworldIncidentAdminsJob
{
    public const string NOTIFICATION_TYPE = 'apworld_incident_opened';

    public function __construct(
        public string $incidentId,
    ) {
    }
}
