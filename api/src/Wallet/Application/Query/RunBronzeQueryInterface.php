<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

interface RunBronzeQueryInterface
{
    /**
     * Story 41.38: a member's bronze in one private run - the balance, and the history newest first, where the
     * credits for checks are grouped by day (one line per check would bury everything else).
     *
     * @return array{balance: int, history: list<array{reason: string, label: string, amount: int, count: int, at: string}>}
     */
    public function ofMember(string $userId, string $runId): array;
}
