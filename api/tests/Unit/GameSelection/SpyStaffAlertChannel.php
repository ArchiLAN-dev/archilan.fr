<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Exception\StaffAlertDeliveryException;
use App\GameSelection\Application\Port\StaffAlertChannelInterface;
use App\GameSelection\Application\Support\StaffAlert;

/**
 * Records staff alerts, or refuses them all to simulate a Discord outage (story 38.2 tests).
 */
final class SpyStaffAlertChannel implements StaffAlertChannelInterface
{
    /** @var list<StaffAlert> */
    public array $posted = [];

    public function __construct(private readonly bool $failing = false)
    {
    }

    public function post(StaffAlert $alert): void
    {
        if ($this->failing) {
            throw new StaffAlertDeliveryException('Discord webhook answered HTTP 500.');
        }
        $this->posted[] = $alert;
    }
}
