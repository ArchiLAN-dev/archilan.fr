<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

/**
 * The two counts behind the admin menu badge (story 38.3).
 */
final readonly class ApworldIncidentSummary
{
    public function __construct(
        public int $active,
        public int $unacknowledged,
    ) {
    }
}
