<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * What one decision pass over the candidates in test did (story 38.6).
 */
final readonly class DecideApworldCandidatesResult
{
    /**
     * @param list<ApworldPromotion> $promotions
     * @param list<string>           $rejectedCandidateIds
     * @param list<string>           $openedIncidentIds    "update rejected" incidents opened
     * @param list<string>           $resolvedIncidentIds  update incidents settled by a promotion
     */
    public function __construct(
        public bool $runnerAvailable,
        public array $promotions = [],
        public array $rejectedCandidateIds = [],
        public array $openedIncidentIds = [],
        public array $resolvedIncidentIds = [],
    ) {
    }
}
