<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Message;

/**
 * Scheduled marker (hourly): takes down the run listings nobody joined for 14 days (story 43.17).
 */
final readonly class ExpireRunListingsMessage
{
}
