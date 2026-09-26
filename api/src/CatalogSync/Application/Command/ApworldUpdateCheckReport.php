<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Command;

final readonly class ApworldUpdateCheckReport
{
    /**
     * @param list<ApworldUpdateAvailable> $updatesAvailable
     * @param int                          $failed           games whose check failed on the network or on
     *                                                       an unreadable answer, and were skipped
     */
    public function __construct(
        public int $checked,
        public bool $rateLimitHit,
        public array $updatesAvailable = [],
        public int $failed = 0,
    ) {
    }
}
