<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\MemberStatsSnapshot;

interface MemberStatsSnapshotRepositoryInterface
{
    public function findByUserId(string $userId): ?MemberStatsSnapshot;

    /**
     * Writes the member's facts, replacing any previous ones, in one statement: two recomputes of a member who has
     * none yet cannot both insert, and nothing stays managed during the hourly pass over every member.
     *
     * @param array<string, int> $facts
     */
    public function store(string $userId, array $facts, \DateTimeImmutable $now): void;
}
