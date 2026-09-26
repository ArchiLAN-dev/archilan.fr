<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

interface ApworldIncidentListQueryInterface
{
    /** How many closed incidents the history shows, the most recently closed first (story 38.3 review). */
    public const int HISTORY_LIMIT = 200;

    /**
     * @return list<ApworldIncidentListItem>
     */
    public function list(ApworldIncidentListScope $scope, ?string $gameId): array;

    public function summary(): ApworldIncidentSummary;
}
