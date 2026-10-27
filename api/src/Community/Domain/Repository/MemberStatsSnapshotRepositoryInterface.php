<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\MemberStatsSnapshot;

interface MemberStatsSnapshotRepositoryInterface
{
    public function findByUserId(string $userId): ?MemberStatsSnapshot;

    /** Persists and flushes: the recompute that writes it flushes nothing when no achievement is granted. */
    public function save(MemberStatsSnapshot $snapshot): void;
}
