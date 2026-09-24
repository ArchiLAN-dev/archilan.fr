<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * What one reconciliation pass changed (story 38.1). The ids feed the alerts of story 38.2:
 * one alert per transition, none per recurrence.
 */
final readonly class ReconcileApworldIncidentsResult
{
    /**
     * @param list<string> $openedIncidentIds
     * @param list<string> $resolvedIncidentIds
     * @param list<string> $ignoredIncidentIds
     */
    public function __construct(
        public bool $runnerAvailable,
        public array $openedIncidentIds = [],
        public array $resolvedIncidentIds = [],
        public array $ignoredIncidentIds = [],
    ) {
    }
}
