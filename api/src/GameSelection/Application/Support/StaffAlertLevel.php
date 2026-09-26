<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Support;

/**
 * How loud a staff alert is (story 38.2). The channel decides what it looks like: Discord maps it to
 * the embed colour.
 */
enum StaffAlertLevel: string
{
    /** Something broke and needs someone. */
    case Alert = 'alert';
    /** Progress on something already known, such as an admin taking it. */
    case Info = 'info';
    /** Closed, by the reconciliation or by an admin. */
    case Resolved = 'resolved';
}
