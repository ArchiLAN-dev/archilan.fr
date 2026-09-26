<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Support;

/**
 * One message for the staff channel (story 38.2), already bounded to the channel's limits.
 */
final readonly class StaffAlert
{
    public function __construct(
        public string $title,
        public string $description,
        public string $url,
        public StaffAlertLevel $level,
    ) {
    }
}
