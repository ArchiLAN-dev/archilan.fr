<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Shared\Application\Support\StatsPeriod;

interface CommunityStatsQueryInterface
{
    /**
     * The Community section of the admin statistics page (story 42.1): what happened in each bucket of the
     * period, the total over the period and the total over the period before. Only counts, never names.
     *
     * - accountsCreated: accounts by creation date; an account erased since stays where it was created.
     * - membershipsStarted: memberships by start date.
     * - activePlayers: distinct accounts holding a slot (owner or co-player) that made a check, read from the
     *   session feed; the total is distinct over the whole period, not a sum of buckets.
     * - friendshipsAccepted, achievementsUnlocked: by acceptance / unlock date.
     *
     * Plus two counts of the moment: accounts that exist (not erased) and members whose membership is current.
     *
     * @return array{
     *     accounts: int,
     *     members: int,
     *     accountsCreated: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int},
     *     membershipsStarted: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int},
     *     activePlayers: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int},
     *     friendshipsAccepted: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int},
     *     achievementsUnlocked: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int}
     * }
     */
    public function stats(StatsPeriod $period, \DateTimeImmutable $now): array;
}
