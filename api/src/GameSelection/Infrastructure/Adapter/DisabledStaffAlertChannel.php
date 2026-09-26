<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure\Adapter;

use App\GameSelection\Application\Port\StaffAlertChannelInterface;
use App\GameSelection\Application\Support\StaffAlert;

/**
 * The staff channel when no Discord webhook is configured (story 38.2): alerts go nowhere, and
 * nothing breaks. The in-app notification to admins still goes out. A production adapter, not a test
 * double, hence `Adapter/` rather than `Double/`.
 */
final readonly class DisabledStaffAlertChannel implements StaffAlertChannelInterface
{
    public function post(StaffAlert $alert): void
    {
    }
}
