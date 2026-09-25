<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

interface ApworldIncidentListQueryInterface
{
    /**
     * @return list<ApworldIncidentListItem>
     */
    public function list(ApworldIncidentListScope $scope, ?string $gameId): array;

    public function summary(): ApworldIncidentSummary;
}
