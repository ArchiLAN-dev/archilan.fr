<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Message;

/**
 * Story 16.23: the last player of a draft run said they are ready; the owner hears of it once it is committed.
 */
final readonly class NotifyRunAllReadyJob
{
    public function __construct(
        public string $runId,
        public string $lastUserId,
    ) {
    }
}
