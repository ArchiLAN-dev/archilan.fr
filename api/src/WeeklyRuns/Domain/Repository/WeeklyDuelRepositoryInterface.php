<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Domain\Repository;

use App\WeeklyRuns\Domain\Entity\WeeklyDuel;
use App\WeeklyRuns\Domain\Entity\WeeklyDuelParticipant;

interface WeeklyDuelRepositoryInterface
{
    public function find(string $duelId): ?WeeklyDuel;

    /**
     * Unresolved duels the member is still in (accepted or not answered yet), the latest first.
     *
     * @return list<WeeklyDuel>
     */
    public function openDuelsOf(string $userId): array;

    /**
     * @return list<WeeklyDuel>
     */
    public function unresolvedForRun(string $weeklyRunId): array;

    /**
     * @param list<string> $duelIds
     *
     * @return array<string, list<WeeklyDuelParticipant>> keyed by duel id, by invitation time
     */
    public function participantsByDuel(array $duelIds): array;

    public function save(WeeklyDuel $duel): void;

    public function saveParticipant(WeeklyDuelParticipant $participant): void;

    public function flush(): void;
}
