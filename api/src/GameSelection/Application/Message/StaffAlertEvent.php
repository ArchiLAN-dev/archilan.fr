<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Message;

/**
 * Which incident transition a staff channel message announces (story 38.2). Carried by the job
 * rather than read back from the incident: by the time the job runs, an opened incident may already
 * have been taken, and the "opened" message must still say "opened".
 */
enum StaffAlertEvent: string
{
    case Opened = 'opened';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
    case Ignored = 'ignored';
}
