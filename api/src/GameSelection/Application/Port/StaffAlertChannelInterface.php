<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Port;

use App\GameSelection\Application\Exception\StaffAlertDeliveryException;
use App\GameSelection\Application\Support\StaffAlert;

/**
 * Where staff alerts go (story 38.2): a Discord webhook in production, nothing when none is set.
 */
interface StaffAlertChannelInterface
{
    /**
     * @throws StaffAlertDeliveryException when the channel refused or could not be reached
     */
    public function post(StaffAlert $alert): void;
}
