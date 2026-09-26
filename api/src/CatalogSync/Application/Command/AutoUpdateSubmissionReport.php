<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Command;

/**
 * What the nightly automatic update did with the available updates (story 38.6).
 */
final readonly class AutoUpdateSubmissionReport
{
    /**
     * @param list<string> $openedIncidentIds "update to review" incidents opened for ambiguous releases
     */
    public function __construct(
        public int $queued = 0,
        public int $deferred = 0,
        public int $skipped = 0,
        public int $failed = 0,
        public array $openedIncidentIds = [],
    ) {
    }
}
